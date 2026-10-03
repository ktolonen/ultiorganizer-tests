<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Scorekeeping links (lib/scorekeeper.functions.php): issuing, opening and
 * the live coverage check behind ScorekeeperGrantCovers().
 *
 * Fixture game 700 is in reservation 500 and game 701 in reservation 501,
 * both in event HRN2026. Tests that need "today" move a reservation's start
 * time and restore it.
 */
final class ScorekeeperFunctionsLibTest extends TestCase
{
    private const KEEPER = 'linkkeeper';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php', 'scorekeeper.functions.php'],
            'pool_stack',
        );
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
        self::flushCaches();
        DBQuery("DELETE FROM uo_scorekeeper_token");
        DBQuery("DELETE FROM uo_scorekeeper_grant");
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0 WHERE season_id='HRN2026'");
        self::asSuperadmin();
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_scorekeeper_token");
        DBQuery("DELETE FROM uo_scorekeeper_grant");
        DBQuery("DELETE FROM uo_users WHERE userid='" . self::KEEPER . "'");
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_reservation SET starttime='2026-06-01 10:00:00' WHERE id=500");
        DBQuery("UPDATE uo_reservation SET starttime='2026-06-01 14:00:00' WHERE id=501");
        DBQuery("UPDATE uo_game SET reservation=500 WHERE game_id=700");
        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0 WHERE game_id=701");
        DBQuery("DELETE FROM uo_scoresheet_history WHERE game=701");
        LegacyApp::closeDatabaseConnection();
    }

    public function testTokenIsCreatedOnceAndReprintingKeepsIt(): void
    {
        $token = ScorekeeperToken('game', 700);

        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame($token, ScorekeeperToken('game', 700));
        $this->assertNotSame($token, ScorekeeperToken('reservation', 500));
        $this->assertSame(2, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scorekeeper_token"));
    }

    public function testOnlyIssuersGetAToken(): void
    {
        self::asUser(self::KEEPER, []);
        $this->assertNull(ScorekeeperToken('game', 700));
        $this->assertNull(ScorekeeperToken('reservation', 500));
        $this->assertNull(ScorekeeperToken('field', 500));

        self::asUser(self::KEEPER, ['resgameadmin' => [500 => 1]]);
        $this->assertIsString(ScorekeeperToken('game', 700));
        $this->assertIsString(ScorekeeperToken('reservation', 500));
        // The reservation right does not reach a game in another reservation.
        $this->assertNull(ScorekeeperToken('game', 701));

        self::asUser(self::KEEPER, ['seriesadmin' => [100 => 1]]);
        $this->assertIsString(ScorekeeperToken('reservation', 501));

        // A grant does not let its holder see or replace the link.
        self::asUser(self::KEEPER, ['gameadmin' => [701 => 1]]);
        $this->assertNull(ScorekeeperToken('game', 701));
    }

    public function testRotatingReplacesTheTokenAndRevokesItsGrants(): void
    {
        $old = ScorekeeperToken('game', 700);
        ScorekeeperAddGrant(self::tokenId($old), self::KEEPER);

        $new = ScorekeeperRotateToken('game', 700);

        $this->assertIsString($new);
        $this->assertNotSame($old, $new);
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scorekeeper_grant"));
        self::asUser(self::KEEPER, []);
        $this->assertSame(['status' => 'invalid'], ScorekeeperRedeemToken($old));
    }

    public function testLoggedInUserOpeningALinkGetsAGrant(): void
    {
        $token = ScorekeeperToken('game', 701);
        self::asUser(self::KEEPER, []);

        $result = ScorekeeperRedeemToken(strtoupper($token));

        $this->assertSame('granted', $result['status']);
        $this->assertSame('HRN2026', $result['season']);
        $this->assertSame(1, (int) DBQueryToValueUncached(
            "SELECT COUNT(*) FROM uo_scorekeeper_grant WHERE userid='" . self::KEEPER . "'",
        ));
        $this->assertSame(self::tokenId($token), ScorekeeperGrantTokenId(701));
        $this->assertSame(0, ScorekeeperGrantTokenId(700));
    }

    public function testMalformedAndUnknownTokensAreInvalid(): void
    {
        self::asUser(self::KEEPER, []);
        foreach (['', 'abc', str_repeat('g', 32), str_repeat('a', 32), "' OR 1=1 -- "] as $token) {
            $this->assertSame(['status' => 'invalid'], ScorekeeperRedeemToken($token));
        }
        $this->assertSame(['status' => 'invalid'], ScorekeeperRedeemToken(['array']));
    }

    public function testLoggedOutUserIsSentToLogInAndClaimsTheLinkAfterwards(): void
    {
        $token = ScorekeeperToken('game', 701);
        self::asAnonymous();

        $result = ScorekeeperRedeemToken($token);

        $this->assertSame('login', $result['status']);
        $this->assertSame(self::tokenId($token), $_SESSION['scorekeeper_pending_token']);
        $this->assertSame(0, ScorekeeperGrantTokenId(701));

        $_SESSION['uid'] = self::KEEPER;
        $this->assertSame('HRN2026', ScorekeeperClaimSessionTokens());
        $this->assertArrayNotHasKey('scorekeeper_pending_token', $_SESSION);
        $this->assertSame(self::tokenId($token), ScorekeeperGrantTokenId(701));
    }

    public function testAnonymousSessionNeedsTheEventSettingOnEveryCheck(): void
    {
        $token = ScorekeeperToken('game', 701);
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=1 WHERE season_id='HRN2026'");
        self::asAnonymous();

        $result = ScorekeeperRedeemToken($token);

        $this->assertSame('granted', $result['status']);
        $this->assertSame([self::tokenId($token)], $_SESSION['scorekeeper_tokens']);
        $this->assertTrue(ScorekeeperSessionHasAnonymousAccess());
        $this->assertSame(self::tokenId($token), ScorekeeperGrantTokenId(701));
        $this->assertSame([701], ScorekeeperGrantedGameIds('HRN2026'));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scorekeeper_grant"));

        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0 WHERE season_id='HRN2026'");
        self::flushCaches();
        $this->assertSame(0, ScorekeeperGrantTokenId(701));
        $this->assertSame([], ScorekeeperGrantedGameIds('HRN2026'));
        $this->assertSame([], ScorekeeperGrantedSeasonIds());
    }

    public function testAnonymousTokensBecomeGrantsOnLogin(): void
    {
        $token = ScorekeeperToken('game', 701);
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=1 WHERE season_id='HRN2026'");
        self::asAnonymous();
        ScorekeeperRedeemToken($token);

        $_SESSION['uid'] = self::KEEPER;
        ScorekeeperClaimSessionTokens();

        $this->assertArrayNotHasKey('scorekeeper_tokens', $_SESSION);
        $this->assertSame(1, (int) DBQueryToValueUncached(
            "SELECT COUNT(*) FROM uo_scorekeeper_grant WHERE userid='" . self::KEEPER . "'",
        ));
    }

    public function testReservationLinkCoversItsGamesOnlyOnTheReservationDate(): void
    {
        $token = ScorekeeperToken('reservation', 500);
        self::asUser(self::KEEPER, []);
        ScorekeeperRedeemToken($token);

        DBQuery("UPDATE uo_reservation SET starttime=CONCAT(DATE('" . date('Y-m-d') . "'), ' 10:00:00') WHERE id=500");
        self::flushCaches();
        $this->assertSame(self::tokenId($token), ScorekeeperGrantTokenId(700));
        $this->assertSame(0, ScorekeeperGrantTokenId(701));
        $this->assertSame([700], ScorekeeperGrantedGameIds('HRN2026'));
        $this->assertSame(['HRN2026'], ScorekeeperGrantedSeasonIds());

        DBQuery("UPDATE uo_reservation SET starttime='" . date('Y-m-d', strtotime('+1 day')) . " 10:00:00' WHERE id=500");
        self::flushCaches();
        $this->assertSame(0, ScorekeeperGrantTokenId(700));
        $this->assertSame([], ScorekeeperGrantedGameIds('HRN2026'));
        // The event is still offered, so the official sees where the link applies.
        $this->assertSame(['HRN2026'], ScorekeeperGrantedSeasonIds());
    }

    public function testGameMovedOutOfTheReservationIsNoLongerCovered(): void
    {
        $token = ScorekeeperToken('reservation', 500);
        self::asUser(self::KEEPER, []);
        ScorekeeperRedeemToken($token);
        DBQuery("UPDATE uo_reservation SET starttime='" . date('Y-m-d') . " 10:00:00' WHERE id=500");
        self::flushCaches();
        $this->assertSame(self::tokenId($token), ScorekeeperGrantTokenId(700));

        DBQuery("UPDATE uo_game SET reservation=501 WHERE game_id=700");
        self::flushCaches();
        $this->assertSame(0, ScorekeeperGrantTokenId(700));
    }

    public function testGrantDoesNotCountOutsideScorekeeper(): void
    {
        $this->assertFalse(defined('UO_APP_SOURCE') && constant('UO_APP_SOURCE') === 'scorekeeper');
        $token = ScorekeeperToken('game', 701);
        self::asUser(self::KEEPER, []);
        ScorekeeperRedeemToken($token);

        $this->assertGreaterThan(0, ScorekeeperGrantTokenId(701));
        $this->assertFalse(ScorekeeperGrantCovers(701));
        $this->assertFalse(hasEditGameEventsRight(701));
        $this->assertFalse(hasEditGamePlayersRight(701));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGrantCountsInScorekeeperUnlessTheEventIsReadOnly(): void
    {
        define('UO_APP_SOURCE', 'scorekeeper');
        $token = ScorekeeperToken('game', 701);
        self::asUser(self::KEEPER, []);
        ScorekeeperRedeemToken($token);

        $this->assertTrue(hasEditGameEventsRight(701));
        $this->assertTrue(hasEditGamePlayersRight(701));
        $this->assertFalse(hasEditGameEventsRight(700));

        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        try {
            self::flushCaches();
            $this->assertFalse(hasEditGameEventsRight(701));
        } finally {
            DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnonymousHistoryRowCarriesTheTokenAndNoAddressWhileVisitorLoggingIsDisabled(): void
    {
        define('UO_APP_SOURCE', 'scorekeeper');
        $token = ScorekeeperToken('game', 701);
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=1 WHERE season_id='HRN2026'");
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', 'false')");
        self::asAnonymous();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        ScorekeeperRedeemToken($token);

        $this->assertTrue(GameSetResult(701, 13, 9, false));

        $rows = DBQueryToArrayUncached(
            "SELECT user_id, ip, target, scorekeeper_token FROM uo_scoresheet_history WHERE game=701 ORDER BY history_id",
        );
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('anonymous', $row['user_id']);
            $this->assertSame(self::tokenId($token), (int) $row['scorekeeper_token']);
            // An anonymous row reaches no registered user's deletion, so it
            // must not keep the visitor's address where logging is off.
            $this->assertSame(IsVisitorLoggingDisabled() ? '' : '203.0.113.7', $row['ip']);
        }
    }

    public function testDeletingTheUserRemovesTheirGrants(): void
    {
        DBQuery("INSERT INTO uo_users (userid, name) VALUES ('" . self::KEEPER . "', 'Keeper')");
        ScorekeeperAddGrant(self::tokenId(ScorekeeperToken('game', 701)), self::KEEPER);

        DeleteUser(self::KEEPER);

        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scorekeeper_grant"));
    }

    private static function tokenId(?string $token): int
    {
        return (int) DBQueryToValueUncached(sprintf(
            "SELECT token_id FROM uo_scorekeeper_token WHERE token='%s'",
            DBEscapeString((string) $token),
        ));
    }

    private static function asSuperadmin(): void
    {
        self::asUser('testuser', ['superadmin' => true]);
    }

    private static function asUser(string $uid, array $roles): void
    {
        $_SESSION = ['uid' => $uid, 'userproperties' => ['userrole' => $roles]];
        self::flushCaches();
    }

    private static function asAnonymous(): void
    {
        $_SESSION = ['uid' => 'anonymous', 'userproperties' => ['userrole' => []]];
        self::flushCaches();
    }

    private static function flushCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
        }
        foreach (['scorekeeper_grant', 'scoresheet_history_snapshot'] as $ns) {
            CacheForgetNamespace($ns);
        }
        $GLOBALS['runtime_cache'] = [];
    }
}
