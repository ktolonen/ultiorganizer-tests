<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Scorekeeping app API helpers (lib/scorekeepingapi.functions.php): the game
 * row lock and the upload concurrency token.
 *
 * Game 701 (team 301 at home, 300 away) is unplayed in the baseline fixture;
 * tearDown() restores it. The playoff test builds its own pools and games.
 */
final class ScorekeepingapiFunctionsLibTest extends TestCase
{
    private const POOL_FROM = 9460;
    private const POOL_TO = 9461;

    private bool $standingsDirty = false;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php', 'standings.functions.php'],
            'team_stack',
        );
        if (
            !is_file(LegacyApp::sutRoot() . '/lib/scorekeepingapi.functions.php')
            || !defined('DB_VERSION')
            || constant('DB_VERSION') < 104
        ) {
            $this->markTestSkipped('The SUT has no scorekeeping app API helpers (needs DB version 104).');
        }
        LegacyApp::requireTopLevelLib('scorekeepingapi.functions.php');
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', 'false')");
        $_SESSION = ['uid' => 'testuser', 'userproperties' => ['userrole' => ['superadmin' => true]]];
        self::flushCaches();
    }

    protected function tearDown(): void
    {
        if (function_exists('DBTransactionDepth')) {
            while (DBTransactionDepth() > 0) {
                DBTransactionRollback();
            }
        }
        if (defined('DB_VERSION')) {
            DBQuery("DELETE FROM uo_game_pool WHERE pool IN (" . self::POOL_FROM . ", " . self::POOL_TO . ")");
            DBQuery("DELETE FROM uo_moveteams WHERE frompool=" . self::POOL_FROM);
            DBQuery("DELETE FROM uo_game WHERE game_id IN (9860)");
            DBQuery("DELETE FROM uo_team_pool WHERE pool IN (" . self::POOL_FROM . ", " . self::POOL_TO . ")");
            DBQuery("DELETE FROM uo_team WHERE team_id IN (9470, 9471)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id IN (" . self::POOL_FROM . ", " . self::POOL_TO . ")");
            DBQuery("DELETE FROM uo_goal WHERE game=701");
            DBQuery("DELETE FROM uo_played WHERE game=701");
            DBQuery("DELETE FROM uo_timeout WHERE game=701");
            DBQuery("DELETE FROM uo_spirit_timeout WHERE game=701");
            DBQuery("DELETE FROM uo_gameevent WHERE game=701");
            DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0, halftime=35, official=NULL, timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=701");
            DBQuery("DELETE FROM uo_scoresheet_history WHERE game=701");
            DBQuery("DELETE FROM uo_defense WHERE game=701");
            DBQuery("UPDATE uo_game SET hometeam=301, visitorteam=300, forfeit=0 WHERE game_id=701");
            DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=0, spiritmode=1003, event_readonly=0, maintenance_mode=0, require_accreditation=0 WHERE season_id='HRN2026'");
            DBQuery("DELETE FROM uo_setting WHERE name LIKE 'sktx_%'");
            DBQuery("DELETE FROM uo_api_receipt WHERE game=701");
            DBQuery("DELETE FROM uo_scorekeeper_token WHERE token_id IN (9930, 9934)");
            DBQuery("UPDATE uo_player SET accredited=1, num=CASE player_id WHEN 800 THEN 8 WHEN 801 THEN 12 WHEN 802 THEN 7 ELSE 14 END WHERE player_id IN (800, 801, 802, 803)");
            DBQuery("DELETE FROM uo_played WHERE game=701");
            if ($this->standingsDirty && function_exists('ResolvePoolStandings')) {
                ResolvePoolStandings(200);
                PoolResolvePlayed(200);
            }
            $this->standingsDirty = false;
        }
        LegacyApp::closeDatabaseConnection();
    }

    public function testLockNeedsAnOpenTransaction(): void
    {
        $this->expectException(LogicException::class);
        GameLockForUpdate(701);
    }

    public function testLockReportsWhetherTheGameExistsAndHoldsTheTransactionOpen(): void
    {
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        DBTransactionBegin();
        $this->assertTrue(GameLockForUpdate(701));
        $this->assertFalse(GameLockForUpdate(999999));
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        DBTransactionRollback();
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
    }

    public function testUploadTokenIsAStableSha256WithoutWrites(): void
    {
        $token = ScorekeeperUploadToken(701);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, ScorekeeperUploadToken(701));
        $this->assertNotSame($token, ScorekeeperUploadToken(700), 'another game has another content');
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701"));
    }

    public function testUploadTokenChangesAfterAGoalAppendWithoutRefreshingCaches(): void
    {
        $before = ScorekeeperUploadToken(701);

        GameAddScoreEntry([
            'game' => 701, 'num' => 1, 'assist' => 802, 'scorer' => 803,
            'time' => 60, 'homescore' => 1, 'visitorscore' => 0,
            'ishomegoal' => 1, 'iscallahan' => 0,
        ]);
        // No manual cache reset: the token forgets the request-local memo itself.
        $afterGoal = ScorekeeperUploadToken(701);

        $this->assertNotSame($before, $afterGoal);
        $this->assertSame($afterGoal, ScorekeeperUploadToken(701));

        GameAddScoreEntry([
            'game' => 701, 'num' => 2, 'assist' => 803, 'scorer' => 802,
            'time' => 120, 'homescore' => 2, 'visitorscore' => 0,
            'ishomegoal' => 1, 'iscallahan' => 0,
        ]);
        $this->assertNotSame($afterGoal, ScorekeeperUploadToken(701));
    }

    public function testUploadTokenOnAGetIgnoresThePersistentCache(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($GLOBALS['uo_persistent_cache_bypass']);
        try {
            if (!IsPersistentCacheEnabled() || PersistentCacheFilePath('db_query_array', 'probe') === null) {
                $this->markTestSkipped('PersistentCacheDir not available');
            }
            // A goal first: the cache admits only non-empty results.
            GameAddScoreEntry([
                'game' => 701, 'num' => 1, 'assist' => 802, 'scorer' => 803,
                'time' => 60, 'homescore' => 1, 'visitorscore' => 0,
                'ishomegoal' => 1, 'iscallahan' => 0,
            ]);
            $before = ScorekeeperUploadToken(701);
            GameAddScoreEntry([
                'game' => 701, 'num' => 2, 'assist' => 803, 'scorer' => 802,
                'time' => 120, 'homescore' => 2, 'visitorscore' => 0,
                'ishomegoal' => 1, 'iscallahan' => 0,
            ]);
            $this->assertNotSame($before, ScorekeeperUploadToken(701));
        } finally {
            unset($GLOBALS['uo_persistent_cache_bypass']);
            $serverConf['PersistentCacheEnabled'] = 'false';
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    }

    public function testUploadTokenFollowsRosterTimeoutsStoppagesResultAndMetadata(): void
    {
        $seen = [ScorekeeperUploadToken(701)];
        $steps = [
            'roster' => fn() => GameAddPlayer(701, 802, 7),
            'timeout' => fn() => GameAddTimeout(701, 1, 300, 1),
            'spirit stoppage' => fn() => GameAddSpiritTimeout(701, 1, 400, 1),
            'result' => fn() => GameSetResult(701, 13, 9, false),
            'halftime' => fn() => GameSetHalftime(701, 40),
            'official' => fn() => GameSetScoreSheetKeeper(701, 'Keeper A'),
            'starting offence' => fn() => GameSetStartingTeam(701, 1),
        ];
        foreach ($steps as $label => $step) {
            $step();
            $token = ScorekeeperUploadToken(701);
            $this->assertNotContains($token, $seen, "the token moves after a $label change");
            $seen[] = $token;
        }
    }

    public function testUploadTokenMovesWhenTheFixtureTeamsChange(): void
    {
        $before = ScorekeeperUploadToken(701);
        // Game 701 has no rosters or goals, so only the teams can move the token.
        DBQuery("UPDATE uo_game SET hometeam=300, visitorteam=301 WHERE game_id=701");
        try {
            $swapped = ScorekeeperUploadToken(701);
            $this->assertNotSame($before, $swapped, 'swapping sides moves the token');
            DBQuery("UPDATE uo_game SET hometeam=NULL WHERE game_id=701");
            $this->assertNotSame($swapped, ScorekeeperUploadToken(701), 'a placeholder side moves the token');
        } finally {
            DBQuery("UPDATE uo_game SET hometeam=301, visitorteam=300 WHERE game_id=701");
        }
        $this->assertSame($before, ScorekeeperUploadToken(701));
    }

    public function testUploadTokenIgnoresNoOpWritesAndTimerOnlyChanges(): void
    {
        GameSetScoreSheetKeeper(701, 'Keeper A');
        GameSetHalftime(701, 40);
        $token = ScorekeeperUploadToken(701);

        // Setting the same values again is a no-op write.
        GameSetScoreSheetKeeper(701, 'Keeper A');
        GameSetHalftime(701, 40);
        $this->assertSame($token, ScorekeeperUploadToken(701));

        // A running clock moves only timer columns, which are outside the scope.
        DBQuery("UPDATE uo_game SET timer_start=" . (time() - 600) . ", timer_paused_duration=30 WHERE game_id=701");
        $this->assertSame($token, ScorekeeperUploadToken(701));
        DBQuery("UPDATE uo_game SET timer_pause_start=" . (time() - 5) . " WHERE game_id=701");
        $this->assertSame($token, ScorekeeperUploadToken(701));

        // Contrast: a real change in the same game does move it.
        GameSetHalftime(701, 41);
        $this->assertNotSame($token, ScorekeeperUploadToken(701));
    }

    public function testUploadTokenIgnoresRosterRowOrderAndStaysEqualForEqualContent(): void
    {
        GameAddPlayer(701, 803, 14);
        GameAddPlayer(701, 802, 7);
        $token = ScorekeeperUploadToken(701);

        DBQuery("DELETE FROM uo_played WHERE game=701");
        GameAddPlayer(701, 802, 7);
        GameAddPlayer(701, 803, 14);

        $this->assertSame($token, ScorekeeperUploadToken(701), 'rosters are sorted by player id');
    }

    public function testFinalizingAPlayoffGameKeepsTheOuterTransactionAndItsLock(): void
    {
        $from = self::POOL_FROM;
        $to = self::POOL_TO;
        $poolColumns = "pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type, follower";
        DBQuery("INSERT INTO uo_pool ($poolColumns) VALUES
            ($to, 'Final', '2', 0, 0, 0, 2, 0, 0, 100, 2, NULL),
            ($from, 'Semi', '1', 0, 0, 0, 2, 0, 0, 100, 2, $to)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
            VALUES (9470, 'Tx T1', $from, 1, 1, 1, 100, 'TX1'), (9471, 'Tx T2', $from, 2, 2, 1, 100, 'TX2')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank)
            VALUES (9470, $from, 1, 1), (9471, $from, 2, 2)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, valid, isongoing, hasstarted)
            VALUES (9860, 9470, 9471, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (9860, $from, 1)");
        DBQuery("INSERT INTO uo_moveteams (frompool, topool, fromplacing, torank) VALUES ($from, $to, 1, 1)");
        $historyBefore = (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=9860");
        self::flushCaches();

        DBTransactionBegin();
        $this->assertTrue(GameLockForUpdate(9860));
        $this->assertTrue(GameSetResult(9860, 15, 9));

        // The resolver ran TeamMove() and wrote the winner into the final pool,
        // all inside the still-open outer transaction.
        $this->assertSame(1, DBTransactionDepth());
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team_pool WHERE pool=$to AND team=9470"));
        $this->assertSame('15', DBQueryToValueUncached("SELECT homescore FROM uo_game WHERE game_id=9860"));

        DBTransactionRollback();

        // An implicit commit in TeamMove() would have kept all of this.
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        $this->assertNull(DBQueryToValueUncached("SELECT homescore FROM uo_game WHERE game_id=9860"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team_pool WHERE pool=$to"));
        $this->assertSame(2, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team_pool WHERE pool=$from"));
        $this->assertSame($historyBefore, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=9860"));
    }

    public function testFinalizingAPlayoffGameCommitsTheMoveWithTheOuterTransaction(): void
    {
        // Contrast for the rollback test: the same flow committed keeps the move.
        $from = self::POOL_FROM;
        $to = self::POOL_TO;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type, follower) VALUES
            ($to, 'Final', '2', 0, 0, 0, 2, 0, 0, 100, 2, NULL),
            ($from, 'Semi', '1', 0, 0, 0, 2, 0, 0, 100, 2, $to)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
            VALUES (9470, 'Tx T1', $from, 1, 1, 1, 100, 'TX1'), (9471, 'Tx T2', $from, 2, 2, 1, 100, 'TX2')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank)
            VALUES (9470, $from, 1, 1), (9471, $from, 2, 2)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, valid, isongoing, hasstarted)
            VALUES (9860, 9470, 9471, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (9860, $from, 1)");
        DBQuery("INSERT INTO uo_moveteams (frompool, topool, fromplacing, torank) VALUES ($from, $to, 1, 1)");
        self::flushCaches();

        DBTransactionBegin();
        GameLockForUpdate(9860);
        $this->assertTrue(GameSetResult(9860, 15, 9));
        DBTransactionCommit();

        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team_pool WHERE pool=$to AND team=9470"));
        $this->assertSame('15', DBQueryToValueUncached("SELECT homescore FROM uo_game WHERE game_id=9860"));
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

    // --- Receipts, wrapper and document check (Phase 2) ---

    private function skipWithoutPhase2(): void
    {
        if (!function_exists('ScorekeepingApiTransaction')) {
            $this->markTestSkipped('The SUT has no scorekeeping API read and upload services.');
        }
    }

    /** Gives game 701 a link covering today and sets the API actor; undone by finishLink(). */
    private function startLink(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
        DBQuery("UPDATE uo_game SET time='$today 14:00:00' WHERE game_id=701");
        DBQuery("DELETE FROM uo_scorekeeper_token WHERE token_id=9930");
        DBQuery("INSERT INTO uo_scorekeeper_token (token_id, token, game) VALUES (9930, 'eeeeeeeeeeeeeeeeeeeeeeeeeeee0701', 701)");
        $_SESSION = [];
        ScorekeeperApiActor(9930, 9931);
    }

    private function finishLink(): void
    {
        ScorekeeperApiActor(0, 0);
        DBQuery("DELETE FROM uo_scorekeeper_token WHERE token_id=9930");
        DBQuery("UPDATE uo_game SET time='2026-06-01 14:00:00' WHERE game_id=701");
        DBQuery("DELETE FROM uo_setting WHERE name LIKE 'sktx_%'");
        DBQuery("DELETE FROM uo_api_receipt WHERE game=701");
    }

    public function testReceiptsReplayPerKeyTokenPairAndGame(): void
    {
        if (!function_exists('ScorekeepingApiWrite')) {
            $this->markTestSkipped('The SUT has no shared scorekeeping API write wrapper.');
        }
        DBQuery("DELETE FROM uo_api_receipt WHERE game IN (700, 701)");
        $this->startLink();
        $writes = 0;
        $write = function () use (&$writes) {
            $writes++;
            return ['status' => 201, 'data' => ['n' => $writes]];
        };
        $send = static fn(string $key, int $api = 9931, int $link = 9930) => ScorekeepingApiWrite(
            701,
            ['api_token' => $api, 'link_token' => $link, 'key' => $key, 'method' => 'POST', 'path' => '/api/v1/games/701/goals', 'body' => ['x' => 1]],
            ['events'],
            $write,
        );
        try {
            $first = $send('k1');
            $this->assertFalse($first['replay']);
            $this->assertSame(1, $writes);

            $again = $send('k1');
            $this->assertTrue($again['replay'], 'the same key replays');
            $this->assertSame([201, $first['body'], $first['token']], [$again['status'], $again['body'], $again['token']]);
            $this->assertSame(1, $writes, 'a replay does not write again');

            $this->assertFalse($send('K1')['replay'], 'keys are case-sensitive');
            $this->assertFalse($send('k1', 9932)['replay'], 'another app token has its own keys');
            $this->assertFalse($send('k1', 9931, 9933)['replay'], 'another link has its own keys');
            DBQuery("INSERT INTO uo_api_receipt (api_token, scorekeeper_token, game, idempotency_key, method, path, request_hash, status, response)
                VALUES (9931, 9930, 700, 'g1', 'POST', '/api/v1/games/701/goals', REPEAT('0', 64), 201, '{}')");
            $this->assertFalse($send('g1')['replay'], 'another game has its own keys');
            $this->assertSame(5, $writes);
        } finally {
            DBQuery("DELETE FROM uo_api_receipt WHERE game=700");
            $this->finishLink();
        }
    }

    public function testIdempotencyKeyAcceptsOneToSixtyFourPrintableAsciiCharacters(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame('a', ScorekeepingApiIdempotencyKey('a'));
        $this->assertSame(str_repeat('~', 64), ScorekeepingApiIdempotencyKey(str_repeat('~', 64)));
        foreach ([null, '', str_repeat('k', 65), "tab\there", "\u{e5}"] as $bad) {
            try {
                ScorekeepingApiIdempotencyKey($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ScorekeepingApiException $e) {
                $this->assertSame(400, $e->httpStatus);
            }
        }
    }

    public function testLinkTokenStringResolvesOnlyLiveWellFormedTokens(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $this->assertSame(9930, ScorekeeperTokenIdFromLink('eeeeeeeeeeeeeeeeeeeeeeeeeeee0701'));
            $this->assertSame(9930, ScorekeeperTokenIdFromLink(' EEEEEEEEEEEEEEEEEEEEEEEEEEEE0701 '));
            $this->assertSame(0, ScorekeeperTokenIdFromLink('eeeeeeeeeeeeeeeeeeeeeeeeeeee0702'));
            $this->assertSame(0, ScorekeeperTokenIdFromLink('short'));
            $this->assertSame(0, ScorekeeperTokenIdFromLink("e' OR '1'='1"));
        } finally {
            $this->finishLink();
        }
    }

    public function testDocumentCheckRefusesUnknownMissingAndMistypedFields(): void
    {
        $this->skipWithoutPhase2();
        $doc = [
            'version' => 1, 'home_team_id' => 301, 'away_team_id' => 300,
            'rosters' => ['home' => [], 'away' => []], 'goals' => [],
            'timeouts' => ['home' => [], 'away' => []], 'spirit_stoppages' => ['home' => [], 'away' => []],
            'result' => null, 'starting_offence' => null, 'halftime' => null, 'official' => null,
        ];
        $this->assertSame($doc, ScorekeeperGameDataNormalize($doc));

        foreach ([
            'unknown' => [$doc + ['extra' => 1], 'extra'],
            'version' => [['version' => 2] + $doc, 'version'],
            'team id as string' => [['home_team_id' => '301'] + $doc, 'home_team_id'],
            'side' => [['goals' => [['side' => 'left', 'scorer_id' => null, 'assist_id' => null, 'callahan' => false, 'time' => 1]]] + $doc, 'goals[0].side'],
            'goal extra' => [['goals' => [['side' => 'home', 'scorer_id' => null, 'assist_id' => null, 'callahan' => false, 'time' => 1, 'num' => 1]]] + $doc, 'goals[0].num'],
            'result state' => [['result' => ['home' => 1, 'away' => 0, 'state' => 'done']] + $doc, 'result.state'],
            'offence' => [['starting_offence' => 'both'] + $doc, 'starting_offence'],
            'not an object' => [[1, 2], ''],
        ] as $label => [$bad, $path]) {
            try {
                ScorekeeperGameDataNormalize($bad);
                $this->fail($label . ' was accepted');
            } catch (ScorekeepingApiException $e) {
                $this->assertSame(400, $e->httpStatus, $label);
                $this->assertContains($path, array_column($e->details, 'path'), $label);
            }
        }
        $missing = $doc;
        unset($missing['official']);
        try {
            ScorekeeperGameDataNormalize($missing);
            $this->fail('a missing field was accepted');
        } catch (ScorekeepingApiException $e) {
            $this->assertSame([['path' => 'official', 'message' => 'Required field is missing.']], $e->details);
        }
    }

    public function testWriteRefusesAGameTheLinkDoesNotCoverWithoutRunningTheWork(): void
    {
        $this->skipWithoutPhase2();
        $ran = false;
        try {
            ScorekeepingApiWrite(
                701,
                ['api_token' => 9931, 'link_token' => 9930, 'key' => 'nc1', 'method' => 'POST', 'path' => '/p', 'body' => []],
                ['events'],
                function () use (&$ran) {
                    $ran = true;
                    return ['status' => 200, 'data' => []];
                },
            );
            $this->fail('expected a refusal');
        } catch (ScorekeepingApiException $e) {
            $this->assertSame([403, 'game_not_covered'], [$e->httpStatus, $e->apiCode]);
        }
        $this->assertFalse($ran);
        $this->assertSame(0, DBTransactionDepth());
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
    }

    public function testTransactionCommitsHoldsTheLockAndDiscardsPrintedOutput(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $seen = ScorekeepingApiTransaction(701, function () {
                echo '<p>printed by a helper</p>';
                DBQuery("INSERT INTO uo_setting (name, value) VALUES ('sktx_commit', 'yes')");
                return [(int) DBQueryToValueUncached("SELECT @@in_transaction"), DBTransactionDepth()];
            });

            $this->assertSame([1, 1], $seen);
            $this->assertSame('yes', DBQueryToValueUncached("SELECT value FROM uo_setting WHERE name='sktx_commit'"));
            $this->assertSame(0, (int) DBQueryToValueUncached("SELECT @@in_transaction"));
        } finally {
            $this->finishLink();
        }
    }

    public function testTransactionRollsBackEveryLevelAndRethrowsOtherFailures(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            try {
                ScorekeepingApiTransaction(701, function () {
                    DBQuery("INSERT INTO uo_setting (name, value) VALUES ('sktx_rollback', 'yes')");
                    DBTransactionBegin();
                    DBQuery("INSERT INTO uo_setting (name, value) VALUES ('sktx_rollback2', 'yes')");
                    throw new RuntimeException('boom');
                });
                $this->fail('expected the failure');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            $this->assertSame(0, DBTransactionDepth());
            $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_setting WHERE name LIKE 'sktx_%'"));
        } finally {
            $this->finishLink();
        }
    }

    public function testTransactionRetriesDeadlocksTwiceThenAnswersBusy(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $attempts = 0;
            $value = ScorekeepingApiTransaction(701, function () use (&$attempts) {
                DBQuery("INSERT INTO uo_setting (name, value) VALUES ('sktx_retry', 'yes')");
                if (++$attempts < 3) {
                    throw new mysqli_sql_exception('Deadlock found when trying to get lock', 1213);
                }
                return 'done';
            });
            $this->assertSame(['done', 3], [$value, $attempts]);
            $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_setting WHERE name='sktx_retry'"), 'the failed attempts were rolled back');

            $attempts = 0;
            try {
                ScorekeepingApiTransaction(701, function () use (&$attempts) {
                    $attempts++;
                    throw new mysqli_sql_exception('Lock wait timeout exceeded', 1205);
                });
                $this->fail('expected 503');
            } catch (ScorekeepingApiException $e) {
                $this->assertSame([503, 'busy', ['Retry-After: 1']], [$e->httpStatus, $e->apiCode, $e->headers]);
            }
            $this->assertSame(3, $attempts, 'the first attempt and two retries');
            $this->assertSame(0, DBTransactionDepth());
        } finally {
            $this->finishLink();
        }
    }

    // --- Pure helpers: state, normalizers, goal checks ---

    private function paths(ScorekeepingApiFields $f): array
    {
        return array_column($f->errors, 'path');
    }

    private function refusal(callable $call): ScorekeepingApiException
    {
        try {
            $call();
        } catch (ScorekeepingApiException $e) {
            return $e;
        }
        $this->fail('expected a ScorekeepingApiException');
    }

    private function gameRow(): array
    {
        return DBQueryToRowUncached("SELECT homescore, visitorscore, hasstarted, isongoing, halftime, official, forfeit FROM uo_game WHERE game_id=701");
    }

    private function setSeason(string $set): void
    {
        DBQuery("UPDATE uo_season SET $set WHERE season_id='HRN2026'");
        self::flushCaches();
    }

    public function testBootstrapMakesDatabaseFailuresThrowAndAbortRollsEverythingBack(): void
    {
        $this->skipWithoutPhase2();
        try {
            ScorekeepingApiBootstrap();
            try {
                DBQueryToValueUncached("SELECT * FROM no_such_table_for_bootstrap");
                $this->fail('a failing query must throw after the bootstrap');
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            }
        } finally {
            DBSetExceptionMode(false);
        }

        DBTransactionBegin();
        DBTransactionBegin();
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('sktx_abort', 'yes')");
        CacheRemember('scorekeeper_grant', 'probe', fn() => 7);
        $this->assertSame(2, DBTransactionDepth());

        ScorekeepingApiAbort();

        $this->assertSame(0, DBTransactionDepth());
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_setting WHERE name='sktx_abort'"));
        $this->assertSame(8, CacheRemember('scorekeeper_grant', 'probe', fn() => 8), 'the grant cache was dropped');
    }

    public function testLockedGameStateReadsTheStoredRowAndFollowsStartAndFinish(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame(
            ['home_team' => 301, 'away_team' => 300, 'homescore' => null, 'visitorscore' => null, 'started' => false, 'final' => false],
            ScorekeeperLockedGameState(701),
        );

        DBQuery("UPDATE uo_game SET homescore=3, visitorscore=2, hasstarted=1, isongoing=1 WHERE game_id=701");
        $ongoing = ScorekeeperLockedGameState(701);
        $this->assertSame([3, 2, true, false], [$ongoing['homescore'], $ongoing['visitorscore'], $ongoing['started'], $ongoing['final']]);

        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        $final = ScorekeeperLockedGameState(701);
        $this->assertSame([true, true], [$final['started'], $final['final']]);

        DBQuery("UPDATE uo_game SET hometeam=NULL, visitorteam=NULL WHERE game_id=701");
        $open = ScorekeeperLockedGameState(701);
        $this->assertSame([null, null], [$open['home_team'], $open['away_team']]);
        DBQuery("UPDATE uo_game SET hometeam=301, visitorteam=300 WHERE game_id=701");

        $e = $this->refusal(fn() => ScorekeeperLockedGameState(999999));
        $this->assertSame([404, 'game_not_found'], [$e->httpStatus, $e->apiCode]);
    }

    public function testMustPassesTruthyResultsAndNamesTheFailedStep(): void
    {
        $this->skipWithoutPhase2();
        ScorekeeperMust(true, 'goals');
        ScorekeeperMust(1, 'goals');
        foreach ([false, 0, null] as $falsy) {
            $e = $this->refusal(fn() => ScorekeeperMust($falsy, 'roster'));
            $this->assertSame([500, 'write_failed', 'The game could not be saved (roster).'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);
        }
    }

    public function testRosterEntriesAreNormalizedAndEachBadEntryNamesItsPath(): void
    {
        $this->skipWithoutPhase2();
        $f = new ScorekeepingApiFields();
        $good = ['player_id' => 5, 'number' => null, 'captain' => true, 'spirit_captain' => false];
        $this->assertSame([$good], ScorekeeperRosterEntriesNormalize($f, [$good], 'players'));
        $this->assertSame([], $f->errors);

        $f = new ScorekeepingApiFields();
        $entries = ScorekeeperRosterEntriesNormalize($f, [
            $good,
            'text',
            ['player_id' => 0, 'number' => 256, 'captain' => 'yes', 'spirit_captain' => false],
            ['player_id' => 6, 'number' => 1, 'captain' => false, 'spirit_captain' => false, 'extra' => 1],
        ], 'rosters.home');
        $this->assertSame([$good], array_slice($entries, 0, 1));
        $this->assertSame(
            ['rosters.home[1]', 'rosters.home[2].player_id', 'rosters.home[2].number', 'rosters.home[2].captain', 'rosters.home[3].extra'],
            $this->paths($f),
        );

        $f = new ScorekeepingApiFields();
        $this->assertSame([], ScorekeeperRosterEntriesNormalize($f, array_fill(0, 101, $good), 'players'));
        $this->assertSame([['path' => 'players', 'message' => 'Must have at most 100 items.']], $f->errors);
        $f = new ScorekeepingApiFields();
        $this->assertSame([], ScorekeeperRosterEntriesNormalize($f, ['a' => 1], 'players'));
        $this->assertSame([['path' => 'players', 'message' => 'Must be a list.']], $f->errors);
    }

    public function testMetadataNormalizeReturnsPresentKeysOrEveryKeyAndRejectsBadValues(): void
    {
        $this->skipWithoutPhase2();
        $f = new ScorekeepingApiFields();
        $this->assertSame(['halftime' => 1800], ScorekeeperMetadataNormalize($f, ['halftime' => 1800], false));
        $this->assertSame([], ScorekeeperMetadataNormalize($f, [], false));
        $this->assertSame(
            ['starting_offence' => null, 'halftime' => null, 'official' => null],
            ScorekeeperMetadataNormalize($f, [], true),
        );
        $this->assertSame(
            ['starting_offence' => 'away', 'halftime' => 0, 'official' => str_repeat('x', 50)],
            ScorekeeperMetadataNormalize($f, ['starting_offence' => 'away', 'halftime' => 0, 'official' => str_repeat('x', 50)], true),
        );
        $this->assertSame([], $f->errors);

        foreach ([
            'starting_offence' => ['both', 'starting_offence'],
            'halftime' => [86401, 'halftime'],
            'official' => [str_repeat('x', 51), 'official'],
        ] as $field => [$bad, $path]) {
            $f = new ScorekeepingApiFields();
            ScorekeeperMetadataNormalize($f, [$field => $bad], false);
            $this->assertSame([$path], $this->paths($f), $field);
        }
        $f = new ScorekeepingApiFields();
        ScorekeeperMetadataNormalize($f, ['official' => 12], false);
        $this->assertSame(['official'], $this->paths($f), 'a non-string official');
    }

    public function testGoalPlayersCheckRefusesCallahanAssistsAndSelfAssists(): void
    {
        $this->skipWithoutPhase2();
        $check = function (array $goal, string $path): array {
            $f = new ScorekeepingApiFields();
            ScorekeeperGoalPlayersCheck($goal, $path, $f);
            return $f->errors;
        };
        $this->assertSame([], $check(['callahan' => false, 'scorer_id' => 1, 'assist_id' => 2], 'goals[0]'));
        $this->assertSame([], $check(['callahan' => true, 'scorer_id' => 1, 'assist_id' => null], ''));
        $this->assertSame([], $check(['callahan' => false, 'scorer_id' => null, 'assist_id' => null], ''));
        $this->assertSame(
            [['path' => 'goals[3].assist_id', 'message' => 'A Callahan goal has no assist.']],
            $check(['callahan' => true, 'scorer_id' => 1, 'assist_id' => 2], 'goals[3]'),
        );
        $this->assertSame(
            [['path' => 'assist_id', 'message' => 'The scorer and the assist must differ.']],
            $check(['callahan' => false, 'scorer_id' => 4, 'assist_id' => 4], ''),
        );
    }

    public function testGoalTimeSynthesizesHiddenTimesAndRequiresOrderedTimesOtherwise(): void
    {
        $this->skipWithoutPhase2();
        $time = function ($time, $previous, bool $hide): array {
            $f = new ScorekeepingApiFields();
            return [ScorekeeperGoalTime($time, $previous, $hide, 'time', $f), array_column($f->errors, 'message')];
        };
        $this->assertSame([11, []], $time(null, 10, true));
        $this->assertSame([11, ['Goal times are hidden in this event; send null.']], $time(5, 10, true));
        $this->assertSame([60, []], $time(60, 10, false));
        $this->assertSame([10, []], $time(10, 10, false), 'equal times are allowed');
        $this->assertSame([10, ['A goal time is required.']], $time(null, 10, false));
        $this->assertSame([9, ['Goal times must not go backwards.']], $time(9, 10, false));
        $this->assertSame(
            [32768, ['The goal time derived from the last goal is too large.']],
            $time(null, SCOREKEEPING_API_MAX_GOAL_TIME, true),
        );
    }

    public function testLiveRequestsAreNormalizedPerOperation(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame(
            ['side' => 'home', 'scorer_id' => 802, 'assist_id' => null, 'callahan' => false, 'time' => 60],
            ScorekeeperLiveRequestNormalize('goal', ['side' => 'home', 'scorer_id' => 802, 'assist_id' => null, 'callahan' => false, 'time' => 60]),
        );
        $this->assertSame(['side' => 'away', 'time' => 30], ScorekeeperLiveRequestNormalize('timeout', ['side' => 'away', 'time' => 30]));
        $this->assertSame(['side' => 'home', 'time' => 0], ScorekeeperLiveRequestNormalize('spirit_stoppage', ['side' => 'home', 'time' => 0]));
        $this->assertSame(['side' => 'home', 'delta' => -1], ScorekeeperLiveRequestNormalize('score_tap', ['side' => 'home', 'delta' => -1]));
        $this->assertSame(['side' => 'away', 'delta' => 1], ScorekeeperLiveRequestNormalize('score_tap', ['side' => 'away', 'delta' => 1]));
        $player = ['player_id' => 802, 'number' => 7, 'captain' => true, 'spirit_captain' => false];
        $this->assertSame(
            ['team_id' => 301, 'players' => [$player]],
            ScorekeeperLiveRequestNormalize('roster', ['team_id' => 301, 'players' => [$player]]),
        );
        $this->assertSame(['halftime' => 1800, 'official' => null], ScorekeeperLiveRequestNormalize('metadata', ['halftime' => 1800, 'official' => null]));
        $this->assertSame(['home' => 15, 'away' => 11], ScorekeeperLiveRequestNormalize('finalize', ['home' => 15, 'away' => 11]));
        $this->assertSame(['time' => 2400, 'point_cap' => 12], ScorekeeperLiveRequestNormalize('cap', ['time' => 2400, 'point_cap' => 12]));

        $bad = [
            ['goal', ['side' => 'left', 'scorer_id' => 1, 'assist_id' => null, 'callahan' => false, 'time' => 1], ['side']],
            ['timeout', ['side' => 'home', 'time' => -1], ['time']],
            ['timeout', ['side' => 'home'], ['time']],
            ['score_tap', ['side' => 'home', 'delta' => 2], ['delta']],
            ['roster', ['team_id' => 'x', 'players' => 'y'], ['team_id', 'players']],
            ['metadata', ['halftime' => 'x'], ['halftime']],
            ['metadata', [], ['']],
            ['metadata', ['nope' => 1], ['nope']],
            ['finalize', ['home' => 15, 'away' => 1001], ['away']],
            ['cap', ['time' => 1, 'point_cap' => 0], ['point_cap']],
            ['cap', 'text', ['']],
        ];
        foreach ($bad as [$operation, $raw, $paths]) {
            $e = $this->refusal(fn() => ScorekeeperLiveRequestNormalize($operation, $raw));
            $this->assertSame([400, 'invalid_body'], [$e->httpStatus, $e->apiCode], $operation . json_encode($raw));
            $this->assertSame($paths, array_column($e->details, 'path'), $operation . json_encode($raw));
        }
        $this->assertSame(
            'Send at least one of starting_offence, halftime and official.',
            $this->refusal(fn() => ScorekeeperLiveRequestNormalize('metadata', []))->details[0]['message'],
        );

        $this->expectException(LogicException::class);
        ScorekeeperLiveRequestNormalize('dance', []);
    }

    public function testDocumentCheckNormalizesRostersGoalsStoppagesAndResult(): void
    {
        $this->skipWithoutPhase2();
        $player = ['player_id' => 802, 'number' => 7, 'captain' => true, 'spirit_captain' => true];
        $goal = ['side' => 'home', 'scorer_id' => 802, 'assist_id' => null, 'callahan' => false, 'time' => 60];
        $doc = [
            'version' => 1, 'home_team_id' => 301, 'away_team_id' => 300,
            'rosters' => ['home' => [$player], 'away' => []], 'goals' => [$goal],
            'timeouts' => ['home' => [['time' => 100]], 'away' => []],
            'spirit_stoppages' => ['home' => [], 'away' => [['time' => 200]]],
            'result' => ['home' => 1, 'away' => 0, 'state' => 'ongoing'],
            'starting_offence' => 'home', 'halftime' => 1800, 'official' => 'Ref',
        ];
        $this->assertSame($doc, ScorekeeperGameDataNormalize($doc));

        $broken = $doc;
        $broken['goals'] = [['side' => 'home'] + ['scorer_id' => 'x', 'assist_id' => null, 'callahan' => 1, 'time' => 1]];
        $broken['timeouts'] = ['home' => [['time' => 86401]], 'away' => 'x'];
        $broken['result'] = ['home' => 1, 'away' => 1001, 'state' => 'done'];
        $broken['rosters'] = ['home' => [], 'away' => [], 'extra' => []];
        $e = $this->refusal(fn() => ScorekeeperGameDataNormalize($broken));
        $this->assertSame(
            ['rosters.extra', 'goals[0].scorer_id', 'goals[0].callahan', 'timeouts.home[0].time', 'timeouts.away', 'result.state', 'result.away'],
            array_column($e->details, 'path'),
        );

        $tooMany = $doc;
        $tooMany['goals'] = array_fill(0, SCOREKEEPING_API_MAX_SIDE_GOALS + 1, $goal);
        $e = $this->refusal(fn() => ScorekeeperGameDataNormalize($tooMany));
        $this->assertSame(
            [['path' => 'goals[' . SCOREKEEPING_API_MAX_SIDE_GOALS . ']', 'message' => 'A side can have at most 255 goals.']],
            $e->details,
        );
    }

    // --- Reads: discovery and entry context ---

    private function ints(string $sql): array
    {
        return array_map(static fn(array $r) => array_map('intval', array_values($r)), DBQueryToArrayUncached($sql));
    }

    private function seedRoster(): void
    {
        foreach ([[802, 7], [803, 14], [800, 8], [801, 12]] as [$player, $number]) {
            $this->assertNotFalse(GameAddPlayer(701, $player, $number));
        }
    }

    private function goal(string $side, ?int $scorer, ?int $assist, ?int $time, bool $callahan = false): array
    {
        return ['side' => $side, 'scorer_id' => $scorer, 'assist_id' => $assist, 'callahan' => $callahan, 'time' => $time];
    }

    public function testDiscoverListsTheGamesALinkCoversWithTeamsAndState(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $found = ScorekeeperDiscoverGames('HRN2026', 9930);
            $this->assertNull($found['available_from']);
            $this->assertCount(1, $found['games']);
            $game = $found['games'][0];
            $this->assertSame(701, $game['game_id']);
            $this->assertSame('2', $game['field']);
            $this->assertSame('Harness Field Complex', $game['location']);
            $this->assertSame(200, $game['pool']['pool_id']);
            $this->assertSame('Pool A', $game['pool']['name']);
            $this->assertSame(['team_id' => 301, 'name' => 'Tampere Tempest'], $game['home']);
            $this->assertSame(['team_id' => 300, 'name' => 'Helsinki Heat'], $game['away']);
            $this->assertSame(['state' => 'not_started', 'home' => null, 'away' => null], $game['result']);

            DBQuery("UPDATE uo_game SET homescore=4, visitorscore=3, hasstarted=1, isongoing=1 WHERE game_id=701");
            $this->assertSame(['state' => 'ongoing', 'home' => 4, 'away' => 3], ScorekeeperDiscoverGames('HRN2026', 9930)['games'][0]['result']);
            DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
            $this->assertSame(['state' => 'final', 'home' => 4, 'away' => 3], ScorekeeperDiscoverGames('HRN2026', 9930)['games'][0]['result']);

            $this->assertSame([], ScorekeeperDiscoverGames('NO_SUCH_EVENT', 9930)['games'], 'another event has none of the link games');
        } finally {
            $this->finishLink();
        }
    }

    public function testDiscoverNamesTheDayALinkOutsideItsDayStartsWorking(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        DBQuery("UPDATE uo_game SET time='2026-06-01 14:00:00' WHERE game_id=701");
        try {
            // Game 701 is far in the past, so the link covers nothing today.
            $byGame = ScorekeeperDiscoverGames('HRN2026', 9930);
            $this->assertSame(['games' => [], 'available_from' => '2026-06-01'], $byGame);

            DBQuery("INSERT INTO uo_scorekeeper_token (token_id, token, reservation) VALUES (9934, 'eeeeeeeeeeeeeeeeeeeeeeeeeeee0501', 501)");
            ScorekeeperApiActor(0, 0);
            $_SESSION = [];
            ScorekeeperApiActor(9934, 9931);
            self::flushCaches();
            $this->assertSame(['games' => [], 'available_from' => '2026-06-01'], ScorekeeperDiscoverGames('HRN2026', 9934), 'by reservation');

            $this->assertSame(['games' => [], 'available_from' => null], ScorekeeperDiscoverGames('HRN2026', 99999), 'an unknown link');
        } finally {
            $this->finishLink();
        }
    }

    public function testEntryContextDescribesAnUntouchedGame(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $context = ScorekeeperEntryContext(701);

            $this->assertSame(
                [
                    'game_id' => 701,
                    'field' => '2',
                    'location' => 'Harness Field Complex',
                    'pool' => ['pool_id' => 200, 'name' => 'Pool A'],
                    'division' => 'Open',
                    'event' => ['event_id' => 'HRN2026', 'name' => 'Harness Invitational 2026', 'timezone' => 'Europe/Helsinki'],
                ],
                array_diff_key($context['game'], ['time' => 1]),
            );
            $this->assertSame('Tampere Tempest', $context['home']['name']);
            $this->assertSame(301, $context['home']['team_id']);
            $this->assertSame([], $context['home']['roster']);
            $this->assertSame(
                [['player_id' => 802, 'name' => 'Timo Twist', 'number' => 7], ['player_id' => 803, 'name' => 'Nia North', 'number' => 14]],
                $context['home']['eligible_players'],
            );
            $this->assertSame('Helsinki Heat', $context['away']['name']);
            $this->assertSame([800, 801], array_column($context['away']['eligible_players'], 'player_id'));
            $this->assertNull($context['result']);
            $this->assertSame([], $context['goals']);
            $this->assertSame(['home' => [], 'away' => []], $context['timeouts']);
            $this->assertSame(['home' => [], 'away' => []], $context['spirit_stoppages']);
            $this->assertSame(['starting_offence' => null, 'halftime' => 35, 'official' => null], $context['metadata']);
            $this->assertSame(
                [
                    'hide_time_on_scoresheet' => false,
                    'timeouts_per_team' => 5,
                    'spirit_stoppages_enabled' => true,
                    'require_accreditation' => false,
                    'max_game_score' => 1000,
                    'max_goals_per_side' => 255,
                    'max_jersey_number' => 255,
                ],
                $context['rules'],
            );
            $this->assertSame(
                ['forfeit' => 'none', 'cap_events' => [], 'defenses' => ['home' => 0, 'away' => 0, 'referenced_player_ids' => []]],
                $context['retained'],
            );
            $this->assertSame(ScorekeeperUploadToken(701), $context['token']);
            $this->assertSame(0, DBTransactionDepth());
        } finally {
            $this->finishLink();
        }
    }

    public function testEntryContextReportsTheKeptGameAndWhatAnUploadMustRetain(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $this->seedRoster();
            GameSetCaptains(701, 301, [802]);
            GameSetSpiritCaptains(701, 300, [801]);
            GameAddScoreEntry(['game' => 701, 'num' => 1, 'assist' => 803, 'scorer' => 802, 'time' => 60, 'homescore' => 1, 'visitorscore' => 0, 'ishomegoal' => 1, 'iscallahan' => 0]);
            GameAddScoreEntry(['game' => 701, 'num' => 2, 'assist' => null, 'scorer' => 800, 'time' => 120, 'homescore' => 1, 'visitorscore' => 1, 'ishomegoal' => 0, 'iscallahan' => 1]);
            $this->assertNotFalse(GameAddTimeout(701, 1, 90, 1));
            $this->assertNotFalse(GameAddTimeout(701, 2, 95, 0));
            $this->assertNotFalse(GameAddSpiritTimeout(701, 1, 99, 0));
            $this->assertNotFalse(GameSetStartingTeam(701, 0));
            $this->assertNotFalse(GameSetCapEvent(701, 'half_cap', 1500, 9));
            DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 803, 70, 0, 1, 1), (701, 2, NULL, 80, 0, 0, 0), (701, 3, 800, 90, 0, 0, 0)");
            DBQuery("UPDATE uo_game SET homescore=1, visitorscore=1, hasstarted=1, isongoing=1, forfeit=2, official='Ref', homedefenses=1, visitordefenses=1 WHERE game_id=701");
            self::flushCaches();

            $context = ScorekeeperEntryContext(701);

            $this->assertSame(
                [['player_id' => 802, 'number' => 7, 'captain' => true, 'spirit_captain' => false], ['player_id' => 803, 'number' => 14, 'captain' => false, 'spirit_captain' => false]],
                $context['home']['roster'],
            );
            $this->assertSame(
                [['player_id' => 800, 'number' => 8, 'captain' => false, 'spirit_captain' => false], ['player_id' => 801, 'number' => 12, 'captain' => false, 'spirit_captain' => true]],
                $context['away']['roster'],
            );
            $this->assertSame(
                [
                    ['num' => 1, 'side' => 'home', 'scorer_id' => 802, 'assist_id' => 803, 'callahan' => false, 'time' => 60, 'home_score' => 1, 'away_score' => 0],
                    ['num' => 2, 'side' => 'away', 'scorer_id' => 800, 'assist_id' => null, 'callahan' => true, 'time' => 120, 'home_score' => 1, 'away_score' => 1],
                ],
                $context['goals'],
            );
            $this->assertSame(['home' => [['time' => 90]], 'away' => [['time' => 95]]], $context['timeouts']);
            $this->assertSame(['home' => [], 'away' => [['time' => 99]]], $context['spirit_stoppages']);
            $this->assertSame(['starting_offence' => 'away', 'halftime' => 35, 'official' => 'Ref'], $context['metadata']);
            $this->assertSame(['home' => 1, 'away' => 1, 'state' => 'ongoing'], $context['result']);
            $this->assertSame(
                [
                    'forfeit' => 'away',
                    'cap_events' => [['type' => 'half_cap', 'time' => 1500, 'point_cap' => 9]],
                    'defenses' => ['home' => 1, 'away' => 1, 'referenced_player_ids' => [800, 803]],
                ],
                $context['retained'],
            );

            DBQuery("UPDATE uo_game SET isongoing=0, forfeit=1 WHERE game_id=701");
            self::flushCaches();
            $final = ScorekeeperEntryContext(701);
            $this->assertSame('final', $final['result']['state']);
            $this->assertSame('home', $final['retained']['forfeit']);
        } finally {
            $this->finishLink();
        }
    }

    public function testEntryContextFollowsTheEventRulesAndOpenFixtures(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $this->seedRoster();
            GameAddScoreEntry(['game' => 701, 'num' => 1, 'assist' => 803, 'scorer' => 802, 'time' => 60, 'homescore' => 1, 'visitorscore' => 0, 'ishomegoal' => 1, 'iscallahan' => 0]);
            GameAddSpiritTimeout(701, 1, 99, 0);
            DBQuery("UPDATE uo_player SET accredited=0 WHERE player_id=803");
            $this->setSeason("hide_time_on_scoresheet=1, spiritmode=0, require_accreditation=1");

            $context = ScorekeeperEntryContext(701);

            $this->assertNull($context['goals'][0]['time'], 'goal times are hidden');
            $this->assertSame(['home' => [], 'away' => []], $context['spirit_stoppages'], 'spirit stoppages are off');
            $this->assertTrue($context['rules']['hide_time_on_scoresheet']);
            $this->assertFalse($context['rules']['spirit_stoppages_enabled']);
            $this->assertTrue($context['rules']['require_accreditation']);
            $this->assertSame(
                [['player_id' => 802, 'name' => 'Timo Twist', 'number' => 7, 'accredited' => true], ['player_id' => 803, 'name' => 'Nia North', 'number' => 14, 'accredited' => false]],
                $context['home']['eligible_players'],
            );

            DBQuery("UPDATE uo_game SET hometeam=NULL WHERE game_id=701");
            self::flushCaches();
            $open = ScorekeeperEntryContext(701);
            $this->assertNull($open['home']['team_id']);
            $this->assertSame([], $open['home']['eligible_players']);
            $this->assertSame([], $open['home']['roster']);
            $this->assertSame('Helsinki Heat', $open['away']['name']);
        } finally {
            DBQuery("UPDATE uo_player SET accredited=1 WHERE player_id=803");
            $this->finishLink();
        }
    }

    public function testEntryContextRefusesUnknownAndUncoveredGames(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        try {
            $e = $this->refusal(fn() => ScorekeeperEntryContext(999999));
            $this->assertSame([404, 'game_not_found'], [$e->httpStatus, $e->apiCode]);
            $this->assertSame(0, DBTransactionDepth());

            $e = $this->refusal(fn() => ScorekeeperEntryContext(700));
            $this->assertSame([403, 'game_not_covered'], [$e->httpStatus, $e->apiCode]);
            $this->assertSame(0, DBTransactionDepth());
            $this->assertSame(701, ScorekeeperEntryContext(701)['game']['game_id'], 'the covered game still works');
        } finally {
            $this->finishLink();
        }
    }

    // --- Wrapper refusals ---

    public function testWriteRefusesMaintenanceReadonlyConflictsAndForfeits(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        $writes = 0;
        $write = function () use (&$writes) {
            $writes++;
            return ['status' => 200, 'data' => []];
        };
        $send = static fn(string $key, array $body = ['x' => 1], string $path = '/api/v1/games/701/goals') => ScorekeepingApiWrite(
            701,
            ['api_token' => 9931, 'link_token' => 9930, 'key' => $key, 'method' => 'POST', 'path' => $path, 'body' => $body],
            ['events'],
            $write,
        );
        try {
            $this->setSeason("maintenance_mode=1");
            $e = $this->refusal(fn() => $send('m1'));
            $this->assertSame([503, 'event_maintenance', ['Retry-After: 60']], [$e->httpStatus, $e->apiCode, $e->headers]);
            $this->setSeason("maintenance_mode=0, event_readonly=1");
            $e = $this->refusal(fn() => $send('m1'));
            $this->assertSame([403, 'event_readonly'], [$e->httpStatus, $e->apiCode]);
            $this->setSeason("event_readonly=0");
            $this->assertSame(0, $writes);

            $first = $send('c1');
            $this->assertFalse($first['replay']);
            foreach ([['c1', ['x' => 2], '/api/v1/games/701/goals'], ['c1', ['x' => 1], '/api/v1/games/701/timeouts']] as [$key, $body, $path]) {
                $e = $this->refusal(fn() => $send($key, $body, $path));
                $this->assertSame([409, 'idempotency_conflict'], [$e->httpStatus, $e->apiCode], $path . json_encode($body));
            }

            DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=701");
            $e = $this->refusal(fn() => $send('f1'));
            $this->assertSame([409, 'forfeit'], [$e->httpStatus, $e->apiCode]);
            $this->assertTrue($send('c1')['replay'], 'a receipt is replayed even for a forfeited game');
            $this->assertSame(1, $writes);
        } finally {
            $this->finishLink();
        }
    }

    public function testWriteStoresTheSanitizedUserAgentUnlessVisitorLoggingIsOff(): void
    {
        $this->skipWithoutPhase2();
        $this->startLink();
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $previous = DBQueryToValueUncached("SELECT value FROM uo_setting WHERE name='DisableVisitorLogging'");
        $this->assertSame('true', $previous, 'the fixture disables visitor logging');
        $send = static fn(string $key) => ScorekeepingApiWrite(
            701,
            ['api_token' => 9931, 'link_token' => 9930, 'key' => $key, 'method' => 'POST', 'path' => '/p', 'body' => []],
            ['events'],
            fn() => ['status' => 200, 'data' => []],
        );
        try {
            DBQuery("UPDATE uo_setting SET value='false' WHERE name='DisableVisitorLogging'");
            $_SERVER['HTTP_USER_AGENT'] = "App/1.0\x01\u{e5}" . str_repeat('x', 300);
            $send('ua1');
            $stored = DBQueryToValueUncached("SELECT user_agent FROM uo_api_receipt WHERE game=701 AND idempotency_key='ua1'");
            $this->assertSame('App/1.0' . str_repeat('x', 248), $stored);

            DBQuery("UPDATE uo_setting SET value='true' WHERE name='DisableVisitorLogging'");
            $send('ua2');
            $this->assertNull(DBQueryToValueUncached("SELECT user_agent FROM uo_api_receipt WHERE game=701 AND idempotency_key='ua2'"));
        } finally {
            DBQuery(sprintf("UPDATE uo_setting SET value='%s' WHERE name='DisableVisitorLogging'", DBEscapeString((string) $previous)));
            if ($agent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $agent;
            }
            $this->finishLink();
        }
    }

    // --- Complete upload ---

    private function uploadDoc(array $over = []): array
    {
        $entry = static fn(int $id, ?int $number, bool $captain = false, bool $spirit = false) => ['player_id' => $id, 'number' => $number, 'captain' => $captain, 'spirit_captain' => $spirit];
        return $over + [
            'version' => 1,
            'home_team_id' => 301,
            'away_team_id' => 300,
            'rosters' => [
                'home' => [$entry(802, 7, true), $entry(803, 14, false, true)],
                'away' => [$entry(800, 8, true), $entry(801, null)],
            ],
            'goals' => [$this->goal('home', 802, 803, 60), $this->goal('away', 800, 801, 120)],
            'timeouts' => ['home' => [['time' => 300]], 'away' => []],
            'spirit_stoppages' => ['home' => [], 'away' => [['time' => 400]]],
            'result' => ['home' => 1, 'away' => 1, 'state' => 'ongoing'],
            'starting_offence' => 'home',
            'halftime' => 1800,
            'official' => 'Ref',
        ];
    }

    private function upload(array $doc): array
    {
        return ScorekeeperReplaceGameData(701, $doc, ScorekeeperUploadToken(701));
    }

    public function testUploadReplacesRostersGoalsStoppagesMetadataAndResult(): void
    {
        $this->skipWithoutPhase2();
        $answer = $this->upload($this->uploadDoc());

        $this->assertSame(
            ['status' => 200, 'data' => ['counts' => ['roster_home' => 2, 'roster_away' => 2, 'goals' => 2, 'timeouts' => 1, 'spirit_stoppages' => 1]]],
            $answer,
        );
        $this->assertSame([[800, 8, 1], [801, 0, 0], [802, 7, 1], [803, 14, 0]], $this->ints("SELECT player, COALESCE(num, 0), captain FROM uo_played WHERE game=701 ORDER BY player"));
        $this->assertSame([[1, 802, 803, 60, 1, 0, 1, 0], [2, 800, 801, 120, 1, 1, 0, 0]], $this->ints("SELECT num, scorer, assist, time, homescore, visitorscore, ishomegoal, iscallahan FROM uo_goal WHERE game=701 ORDER BY num"));
        $this->assertSame([[1, 300, 1]], $this->ints("SELECT num, time, ishome FROM uo_timeout WHERE game=701"));
        $this->assertSame([[1, 400, 0]], $this->ints("SELECT num, time, ishome FROM uo_spirit_timeout WHERE game=701"));
        $this->assertSame([[1]], $this->ints("SELECT ishome FROM uo_gameevent WHERE game=701 AND type='offence'"));
        $this->assertSame(
            ['homescore' => '1', 'visitorscore' => '1', 'hasstarted' => '1', 'isongoing' => '1', 'halftime' => '1800', 'official' => 'Ref', 'forfeit' => '0'],
            $this->gameRow(),
        );

        // The same document again is a no-op for the content, and the token moves only with content.
        $token = ScorekeeperUploadToken(701);
        $this->upload($this->uploadDoc());
        $this->assertSame($token, ScorekeeperUploadToken(701));

        // Dropping players, goals and stoppages removes them.
        $this->upload($this->uploadDoc([
            'rosters' => ['home' => [['player_id' => 802, 'number' => 9, 'captain' => false, 'spirit_captain' => false]], 'away' => []],
            'goals' => [],
            'timeouts' => ['home' => [], 'away' => []],
            'spirit_stoppages' => ['home' => [], 'away' => []],
            'result' => ['home' => 0, 'away' => 0, 'state' => 'ongoing'],
            'starting_offence' => null,
            'halftime' => null,
            'official' => null,
        ]));
        $this->assertSame([[802, 9, 0]], $this->ints("SELECT player, COALESCE(num, 0), captain FROM uo_played WHERE game=701"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_timeout WHERE game=701"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_spirit_timeout WHERE game=701"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_gameevent WHERE game=701 AND type='offence'"));
        $row = $this->gameRow();
        $this->assertSame([null, null], [$row['official'], $row['halftime']]);
    }

    public function testUploadFinalizesAndReopensAGameAndClearsAResult(): void
    {
        $this->skipWithoutPhase2();
        $this->standingsDirty = true;

        $this->upload($this->uploadDoc(['result' => ['home' => 1, 'away' => 1, 'state' => 'final']]));
        $row = $this->gameRow();
        $this->assertSame(['1', '1', '2', '0'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']], 'a final game is hasstarted=2');

        // Final back to ongoing recomputes the pool standings (checked through the stored state).
        $this->upload($this->uploadDoc(['result' => ['home' => 1, 'away' => 1, 'state' => 'ongoing']]));
        $row = $this->gameRow();
        $this->assertSame(['1', '1', '1', '1'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']]);

        $this->upload($this->uploadDoc(['goals' => [], 'result' => null]));
        $row = $this->gameRow();
        $this->assertSame([null, null, '0', '0'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']]);
    }

    public function testUploadRefusesAStaleTokenAndChangedFixtures(): void
    {
        $this->skipWithoutPhase2();
        $doc = $this->uploadDoc();
        foreach (['*', 'deadbeef', ''] as $ifMatch) {
            $e = $this->refusal(fn() => ScorekeeperReplaceGameData(701, $doc, $ifMatch));
            $this->assertSame([409, 'stale_token'], [$e->httpStatus, $e->apiCode], "If-Match '$ifMatch'");
        }
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_goal WHERE game=701"));

        foreach ([['home_team_id', 300], ['away_team_id', null]] as [$field, $value]) {
            $e = $this->refusal(fn() => $this->upload([$field => $value] + $doc));
            $this->assertSame([409, 'fixture_changed'], [$e->httpStatus, $e->apiCode], $field);
        }
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"));
    }

    public function testUploadValidationNamesEveryOffendingField(): void
    {
        $this->skipWithoutPhase2();
        DBQuery("UPDATE uo_player SET accredited=0 WHERE player_id=801");
        $this->setSeason("require_accreditation=1");
        $entry = static fn(int $id) => ['player_id' => $id, 'number' => 1, 'captain' => false, 'spirit_captain' => false];
        $doc = $this->uploadDoc([
            'rosters' => ['home' => [$entry(802), $entry(802), $entry(800)], 'away' => [$entry(99999), $entry(801)]],
            'goals' => [
                $this->goal('home', 803, 802, 100),
                $this->goal('home', 802, 802, 50, true),
                $this->goal('away', null, null, null),
            ],
            'timeouts' => ['home' => array_fill(0, 6, ['time' => 1]), 'away' => []],
            'spirit_stoppages' => ['home' => array_fill(0, 5, ['time' => 1]), 'away' => []],
            'result' => ['home' => 5, 'away' => 5, 'state' => 'ongoing'],
        ]);

        $e = $this->refusal(fn() => $this->upload($doc));

        $this->assertSame([400, 'validation_failed'], [$e->httpStatus, $e->apiCode]);
        $messages = [];
        foreach ($e->details as $d) {
            $messages[$d['path']][] = $d['message'];
        }
        $this->assertSame(['The player is listed more than once.'], $messages['rosters.home[1].player_id']);
        $this->assertSame(['The player does not belong to the home team.'], $messages['rosters.home[2].player_id']);
        $this->assertSame(['The player does not exist.'], $messages['rosters.away[0].player_id']);
        $this->assertSame(['The player is not accredited.'], $messages['rosters.away[1].player_id']);
        $this->assertSame(['The player is not on the home roster in the document.'], $messages['goals[0].scorer_id']);
        $this->assertSame(
            ['A Callahan goal has no assist.', 'The scorer and the assist must differ.'],
            $messages['goals[1].assist_id'],
        );
        $this->assertSame(['Goal times must not go backwards.'], $messages['goals[1].time']);
        $this->assertSame(['A goal time is required.'], $messages['goals[2].time']);
        $this->assertSame(['The result must equal the goal total 2-1.'], $messages['result']);
        $this->assertSame(['At most 5 timeouts are allowed.'], $messages['timeouts.home']);
        $this->assertSame(['At most 4 spirit stoppages are allowed.'], $messages['spirit_stoppages.home']);
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"), 'nothing was written');

        $e = $this->refusal(fn() => $this->upload($this->uploadDoc(['result' => null])));
        $this->assertSame([['path' => 'result', 'message' => 'A null result cannot come with goals.']], array_values(array_filter($e->details, fn($d) => $d['path'] === 'result')));

        DBQuery("UPDATE uo_player SET accredited=1 WHERE player_id=801");
        $this->setSeason("require_accreditation=0");
        $this->setSeason("spiritmode=0");
        $e = $this->refusal(fn() => $this->upload($this->uploadDoc()));
        $this->assertSame([['path' => 'spirit_stoppages.away', 'message' => 'Spirit stoppages are not used in this event.']], $e->details);
    }

    public function testUploadKeepsHiddenTimesAndSpiritStoppagesOutOfEventsThatDoNotUseThem(): void
    {
        $this->skipWithoutPhase2();
        $this->setSeason("hide_time_on_scoresheet=1, spiritmode=0");

        $e = $this->refusal(fn() => $this->upload($this->uploadDoc(['spirit_stoppages' => ['home' => [], 'away' => []]])));
        $this->assertSame(
            ['goals[0].time', 'goals[1].time'],
            array_column($e->details, 'path'),
            'times must be null',
        );

        $this->upload($this->uploadDoc([
            'goals' => [$this->goal('home', 802, 803, null), $this->goal('away', 800, 801, null)],
            'spirit_stoppages' => ['home' => [], 'away' => []],
        ]));
        $this->assertSame([[1, 1], [2, 2]], $this->ints("SELECT num, time FROM uo_goal WHERE game=701 ORDER BY num"), 'times are synthesized one second apart');
    }

    public function testUploadRefusesToDropAPlayerOnTheDefenceSheet(): void
    {
        $this->skipWithoutPhase2();
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 803, 70, 0, 1, 1), (701, 2, 800, 80, 0, 1, 0)");
        $without = ['home' => [['player_id' => 802, 'number' => 7, 'captain' => false, 'spirit_captain' => false]], 'away' => []];

        $e = $this->refusal(fn() => $this->upload($this->uploadDoc(['rosters' => $without, 'goals' => [], 'result' => null])));
        $this->assertSame([409, 'retained_defense', ['player_ids' => [800, 803]]], [$e->httpStatus, $e->apiCode, $e->details]);
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"));

        $this->upload($this->uploadDoc());
        $this->assertSame(4, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"), 'keeping them is fine');
    }

    // --- Metadata ---

    public function testMetadataUpdateTouchesOnlyTheKeysSentAndSkipsUnchangedValues(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame(['status' => 200, 'data' => []], ScorekeeperUpdateMetadata(701, ['starting_offence' => 'away', 'halftime' => 1500, 'official' => 'Ref']));
        $this->assertSame([[0]], $this->ints("SELECT ishome FROM uo_gameevent WHERE game=701 AND type='offence'"));
        $row = $this->gameRow();
        $this->assertSame(['1500', 'Ref'], [$row['halftime'], $row['official']]);

        ScorekeeperUpdateMetadata(701, ['starting_offence' => 'home']);
        $this->assertSame([[1]], $this->ints("SELECT ishome FROM uo_gameevent WHERE game=701 AND type='offence'"));
        $row = $this->gameRow();
        $this->assertSame(['1500', 'Ref'], [$row['halftime'], $row['official']], 'absent keys are left alone');

        $history = (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701");
        ScorekeeperUpdateMetadata(701, ['starting_offence' => 'home', 'halftime' => 1500, 'official' => 'Ref']);
        $this->assertSame($history, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701"), 'unchanged values write nothing');

        ScorekeeperUpdateMetadata(701, ['starting_offence' => null, 'halftime' => 0, 'official' => '']);
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_gameevent WHERE game=701 AND type='offence'"));
        $row = $this->gameRow();
        $this->assertSame(['0', null], [$row['halftime'], $row['official']]);
    }

    // --- Live writes ---

    public function testAddGoalAppendsNumbersAndMovesTheResult(): void
    {
        $this->skipWithoutPhase2();
        $this->seedRoster();

        $first = ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 60));
        $this->assertSame(
            ['status' => 201, 'data' => ['goal' => ['num' => 1, 'side' => 'home', 'scorer_id' => 802, 'assist_id' => 803, 'callahan' => false, 'time' => 60, 'home_score' => 1, 'away_score' => 0]]],
            $first,
        );
        $row = $this->gameRow();
        $this->assertSame(['1', '0', '1', '1'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']]);

        $second = ScorekeeperAddGoal(701, $this->goal('away', 800, null, 60, true));
        $this->assertSame([2, 'away', true, 1, 1], [$second['data']['goal']['num'], $second['data']['goal']['side'], $second['data']['goal']['callahan'], $second['data']['goal']['home_score'], $second['data']['goal']['away_score']]);
        $this->assertSame([[1, 802, 803, 60, 1, 0, 1], [2, 800, 0, 60, 1, 1, 0]], $this->ints("SELECT num, scorer, COALESCE(assist, 0), time, homescore, visitorscore, ishomegoal FROM uo_goal WHERE game=701 ORDER BY num"));

        // A point tapped for the other team on the result page is kept.
        DBQuery("UPDATE uo_game SET homescore=5, visitorscore=1 WHERE game_id=701");
        ScorekeeperAddGoal(701, $this->goal('home', 803, null, 90));
        $row = $this->gameRow();
        $this->assertSame(['5', '1'], [$row['homescore'], $row['visitorscore']], 'a higher stored score stays');
        ScorekeeperAddGoal(701, $this->goal('away', 801, 800, 95));
        $row = $this->gameRow();
        $this->assertSame(['5', '2'], [$row['homescore'], $row['visitorscore']]);
    }

    public function testAddGoalRefusesFinalGamesUnsetTeamsAndInvalidGoals(): void
    {
        $this->skipWithoutPhase2();
        $this->seedRoster();
        $this->assertSame(201, ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 60))['status']);

        $errors = function (array $goal): array {
            $e = $this->refusal(fn() => ScorekeeperAddGoal(701, $goal));
            $this->assertSame([400, 'validation_failed'], [$e->httpStatus, $e->apiCode]);
            return $e->details;
        };
        $this->assertSame(
            [['path' => 'scorer_id', 'message' => 'The player is not on the home game roster.']],
            $errors($this->goal('home', 800, 803, 70)),
        );
        $this->assertSame(
            [['path' => 'assist_id', 'message' => 'The player is not on the away game roster.']],
            $errors($this->goal('away', 800, 802, 70)),
        );
        $this->assertSame(
            [['path' => 'assist_id', 'message' => 'A Callahan goal has no assist.']],
            $errors($this->goal('home', 802, 803, 70, true)),
        );
        $this->assertSame(
            [['path' => 'time', 'message' => 'Goal times must not go backwards.']],
            $errors($this->goal('home', 802, 803, 30)),
        );
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_goal WHERE game=701"), 'refused goals write nothing');

        DBQuery("UPDATE uo_goal SET homescore=" . SCOREKEEPING_API_MAX_SIDE_GOALS . " WHERE game=701");
        $this->assertSame(
            [['path' => 'side', 'message' => 'A side can have at most 255 goals.']],
            $errors($this->goal('home', 802, 803, 70)),
        );
        DBQuery("UPDATE uo_goal SET homescore=1 WHERE game=701");

        DBQuery("UPDATE uo_game SET hometeam=NULL WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 70)));
        $this->assertSame([409, 'team_not_set'], [$e->httpStatus, $e->apiCode]);
        DBQuery("UPDATE uo_game SET hometeam=301 WHERE game_id=701");

        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 70)));
        $this->assertSame([409, 'game_final'], [$e->httpStatus, $e->apiCode]);
    }

    public function testAddGoalHidesTimesWhenTheEventDoes(): void
    {
        $this->skipWithoutPhase2();
        $this->seedRoster();
        $this->setSeason("hide_time_on_scoresheet=1");

        $e = $this->refusal(fn() => ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 60)));
        $this->assertSame([['path' => 'time', 'message' => 'Goal times are hidden in this event; send null.']], $e->details);

        $first = ScorekeeperAddGoal(701, $this->goal('home', 802, 803, null));
        $second = ScorekeeperAddGoal(701, $this->goal('away', 800, 801, null));
        $this->assertSame([null, null], [$first['data']['goal']['time'], $second['data']['goal']['time']]);
        $this->assertSame([[1, 1], [2, 2]], $this->ints("SELECT num, time FROM uo_goal WHERE game=701 ORDER BY num"));
    }

    public function testDeleteLastGoalRemovesOnlyTheLastAndLowersTheResult(): void
    {
        $this->skipWithoutPhase2();
        $this->seedRoster();
        $e = $this->refusal(fn() => ScorekeeperDeleteLastGoal(701, 1));
        $this->assertSame([409, 'goal_changed'], [$e->httpStatus, $e->apiCode], 'no goal to delete');

        ScorekeeperAddGoal(701, $this->goal('home', 802, 803, 60));
        ScorekeeperAddGoal(701, $this->goal('away', 800, 801, 90));
        $e = $this->refusal(fn() => ScorekeeperDeleteLastGoal(701, 1));
        $this->assertSame([409, 'goal_changed', 'Only the last goal can be deleted, and goal 1 is no longer the last one.'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);
        $this->assertSame(2, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_goal WHERE game=701"));

        $this->assertSame(['status' => 200, 'data' => ['deleted' => ['num' => 2]]], ScorekeeperDeleteLastGoal(701, 2));
        $this->assertSame([[1]], $this->ints("SELECT num FROM uo_goal WHERE game=701"));
        $row = $this->gameRow();
        $this->assertSame(['1', '0'], [$row['homescore'], $row['visitorscore']]);

        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperDeleteLastGoal(701, 1));
        $this->assertSame([409, 'game_final'], [$e->httpStatus, $e->apiCode]);
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
    }

    public function testStoppagesAreNumberedPerTeamAndStopAtTheLimit(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame(
            ['status' => 201, 'data' => ['timeout' => ['side' => 'home', 'num' => 1, 'time' => 100]]],
            ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 100]),
        );
        $this->assertSame(1, ScorekeeperAddStoppage(701, 'timeout', ['side' => 'away', 'time' => 110])['data']['timeout']['num'], 'the other team counts separately');
        $this->assertSame(2, ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 120])['data']['timeout']['num']);
        $limit = GameTimeoutsPerTeam(701);
        $this->assertSame(5, $limit);
        for ($n = 3; $n <= $limit; $n++) {
            ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 100 + $n]);
        }
        $e = $this->refusal(fn() => ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 999]));
        $this->assertSame([409, 'timeout_limit', 'The team has used all 5 timeouts.'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);
        $this->assertSame(5, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_timeout WHERE game=701 AND ishome=1"));

        $this->assertSame(
            ['status' => 201, 'data' => ['spirit_stoppage' => ['side' => 'away', 'num' => 1, 'time' => 50]]],
            ScorekeeperAddStoppage(701, 'spirit_stoppage', ['side' => 'away', 'time' => 50]),
        );
        for ($n = 2; $n <= 4; $n++) {
            ScorekeeperAddStoppage(701, 'spirit_stoppage', ['side' => 'away', 'time' => 50 + $n]);
        }
        $e = $this->refusal(fn() => ScorekeeperAddStoppage(701, 'spirit_stoppage', ['side' => 'away', 'time' => 99]));
        $this->assertSame([409, 'spirit_stoppage_limit', 'The team has used all 4 spirit stoppages.'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);

        $this->setSeason("spiritmode=0");
        $e = $this->refusal(fn() => ScorekeeperAddStoppage(701, 'spirit_stoppage', ['side' => 'home', 'time' => 1]));
        $this->assertSame([409, 'spirit_stoppages_disabled'], [$e->httpStatus, $e->apiCode]);
    }

    public function testScoreTapsMoveTheScoreWithinZeroAndTheMaximum(): void
    {
        $this->skipWithoutPhase2();
        $this->assertSame(['status' => 200, 'data' => []], ScorekeeperApplyScoreTap(701, ['side' => 'home', 'delta' => 1]));
        $row = $this->gameRow();
        $this->assertSame(['1', '0', '1', '1'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']]);
        ScorekeeperApplyScoreTap(701, ['side' => 'away', 'delta' => 1]);
        ScorekeeperApplyScoreTap(701, ['side' => 'home', 'delta' => -1]);
        $row = $this->gameRow();
        $this->assertSame(['0', '1'], [$row['homescore'], $row['visitorscore']]);

        $e = $this->refusal(fn() => ScorekeeperApplyScoreTap(701, ['side' => 'home', 'delta' => -1]));
        $this->assertSame([409, 'score_out_of_range', 'A score must stay between 0 and 1000.'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);

        DBQuery("UPDATE uo_game SET homescore=" . MAX_GAME_SCORE . " WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperApplyScoreTap(701, ['side' => 'home', 'delta' => 1]));
        $this->assertSame('score_out_of_range', $e->apiCode);
        $this->assertSame('1000', $this->gameRow()['homescore']);

        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperApplyScoreTap(701, ['side' => 'away', 'delta' => 1]));
        $this->assertSame([409, 'game_final'], [$e->httpStatus, $e->apiCode]);
    }

    public function testRosterReplacementAddsRenumbersRemovesAndSetsCaptains(): void
    {
        $this->skipWithoutPhase2();
        $player = static fn(int $id, ?int $number, bool $captain = false, bool $spirit = false) => ['player_id' => $id, 'number' => $number, 'captain' => $captain, 'spirit_captain' => $spirit];

        $this->assertSame(
            ['status' => 200, 'data' => ['roster' => ['side' => 'home', 'players' => 2]]],
            ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802, 7, true), $player(803, 14, false, true)]]),
        );
        $this->assertSame([[802, 7, 1, 0], [803, 14, 0, 1]], $this->ints("SELECT player, num, captain, spirit_captain FROM uo_played WHERE game=701 ORDER BY player"));

        ScorekeeperReplaceRoster(701, 'away', ['team_id' => 300, 'players' => [$player(800, 8)]]);
        ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802, 9, false, true)]]);
        $this->assertSame([[800, 8, 0, 0], [802, 9, 0, 1]], $this->ints("SELECT player, num, captain, spirit_captain FROM uo_played WHERE game=701 ORDER BY player"), 'the other side is untouched');
    }

    public function testRosterReplacementRefusesWrongTeamsBadPlayersAndReferencedPlayers(): void
    {
        $this->skipWithoutPhase2();
        $player = static fn(int $id) => ['player_id' => $id, 'number' => 1, 'captain' => false, 'spirit_captain' => false];
        foreach ([['home', 300], ['away', 301]] as [$side, $team]) {
            $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, $side, ['team_id' => $team, 'players' => []]));
            $this->assertSame([409, 'fixture_changed'], [$e->httpStatus, $e->apiCode], $side);
        }
        DBQuery("UPDATE uo_game SET hometeam=NULL WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => []]));
        $this->assertSame('fixture_changed', $e->apiCode, 'a team that is not set yet');
        DBQuery("UPDATE uo_game SET hometeam=301 WHERE game_id=701");

        DBQuery("UPDATE uo_player SET accredited=0 WHERE player_id=803");
        $this->setSeason("require_accreditation=1");
        $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802), $player(802), $player(800), $player(99999), $player(803)]]));
        $this->assertSame([400, 'validation_failed'], [$e->httpStatus, $e->apiCode]);
        $this->assertSame(
            [
                ['path' => 'players[1].player_id', 'message' => 'The player is listed more than once.'],
                ['path' => 'players[2].player_id', 'message' => 'The player does not belong to the home team.'],
                ['path' => 'players[3].player_id', 'message' => 'The player does not exist.'],
                ['path' => 'players[4].player_id', 'message' => 'The player is not accredited.'],
            ],
            $e->details,
        );
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"));
        DBQuery("UPDATE uo_player SET accredited=1 WHERE player_id=803");
        $this->setSeason("require_accreditation=0");

        ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802), $player(803)]]);
        GameAddScoreEntry(['game' => 701, 'num' => 1, 'assist' => 803, 'scorer' => 802, 'time' => 60, 'homescore' => 1, 'visitorscore' => 0, 'ishomegoal' => 1, 'iscallahan' => 0]);
        $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => []]));
        $this->assertSame([409, 'player_referenced', ['player_ids' => [802, 803]]], [$e->httpStatus, $e->apiCode, $e->details]);
        $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802)]]));
        $this->assertSame(['player_ids' => [803]], $e->details, 'only the referenced player that would be dropped');
        $this->assertSame(2, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_played WHERE game=701"));
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 803, 70, 0, 1, 1)");
        DBQuery("DELETE FROM uo_goal WHERE game=701");
        $e = $this->refusal(fn() => ScorekeeperReplaceRoster(701, 'home', ['team_id' => 301, 'players' => [$player(802)]]));
        $this->assertSame(['player_ids' => [803]], $e->details, 'the defence sheet refers to the player too');
    }

    public function testCapsAreSetCorrectedAndRemovedWithinTheRules(): void
    {
        $this->skipWithoutPhase2();
        DBQuery("UPDATE uo_game SET homescore=3, visitorscore=2, hasstarted=1, isongoing=1 WHERE game_id=701");

        $e = $this->refusal(fn() => ScorekeeperChangeCap(701, 'half_cap', ['time' => 1500, 'point_cap' => 3]));
        $this->assertSame([409, 'point_cap_too_low', 'A new point cap must be greater than the current score (3).'], [$e->httpStatus, $e->apiCode, $e->getMessage()]);
        $this->assertSame([], GameCapEvents(701));

        $this->assertSame(
            ['status' => 200, 'data' => ['cap' => ['type' => 'half_cap', 'time' => 1500, 'point_cap' => 4]]],
            ScorekeeperChangeCap(701, 'half_cap', ['time' => 1500, 'point_cap' => 4]),
        );
        $caps = GameCapEvents(701);
        $this->assertSame(['half_cap'], array_keys($caps));
        $this->assertSame(['1500', '4'], [$caps['half_cap']['time'], $caps['half_cap']['info']]);

        ScorekeeperChangeCap(701, 'half_cap', ['time' => 1600, 'point_cap' => 2]);
        $caps = GameCapEvents(701);
        $this->assertSame(['1600', '2'], [$caps['half_cap']['time'], $caps['half_cap']['info']], 'a recorded cap may be corrected to any value');

        $this->assertSame(['status' => 200, 'data' => ['cap' => null]], ScorekeeperChangeCap(701, 'half_cap', null));
        $this->assertSame([], GameCapEvents(701));

        $this->setSeason("hide_time_on_scoresheet=1");
        foreach ([['time_cap', ['time' => 1, 'point_cap' => 9]], ['time_cap', null]] as [$type, $cap]) {
            $e = $this->refusal(fn() => ScorekeeperChangeCap(701, $type, $cap));
            $this->assertSame([409, 'times_hidden'], [$e->httpStatus, $e->apiCode]);
        }
    }

    private function skipWithoutCorrections(): void
    {
        $this->skipWithoutPhase2();
        if (!function_exists('ScorekeeperChangeClock')) {
            $this->markTestSkipped('The SUT has no clock, stoppage delete, goal correction or note writes.');
        }
    }

    public function testClockStateReadsTheTimerColumns(): void
    {
        $this->skipWithoutCorrections();
        $row = ['hasstarted' => 0, 'isongoing' => 0, 'timer_start' => null, 'timer_pause_start' => null, 'timer_paused_duration' => 0];
        $this->assertSame(['state' => 'not_started', 'elapsed' => 0, 'basis' => 0], ScorekeeperClockState($row));
        $this->assertSame('stopped', ScorekeeperClockState(['hasstarted' => 1, 'isongoing' => 1] + $row)['state'], 'kept without a clock');
        $this->assertSame('stopped', ScorekeeperClockState(['hasstarted' => 2, 'timer_start' => 1000] + $row)['state'], 'final');

        $now = time();
        $running = ScorekeeperClockState(['hasstarted' => 1, 'isongoing' => 1, 'timer_start' => $now - 100, 'timer_paused_duration' => 30] + $row);
        $this->assertSame(['running', $now - 70], [$running['state'], $running['basis']]);
        $this->assertEqualsWithDelta(70, $running['elapsed'], 2);
        $paused = ScorekeeperClockState(['hasstarted' => 1, 'isongoing' => 1, 'timer_start' => $now - 100, 'timer_pause_start' => $now - 10, 'timer_paused_duration' => 30] + $row);
        $this->assertSame(['state' => 'paused', 'elapsed' => 60, 'basis' => 60], $paused);
    }

    public function testClockChangesFollowTheScorekeeperControls(): void
    {
        $this->skipWithoutCorrections();
        $start = ScorekeeperChangeClock(701, ['action' => 'start', 'elapsed' => null]);
        $this->assertSame(['status' => 200, 'data' => ['clock' => ['state' => 'running', 'elapsed' => 0]]], $start);
        $e = $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'start', 'elapsed' => null]));
        $this->assertSame([409, 'clock_running'], [$e->httpStatus, $e->apiCode]);
        $e = $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'set', 'elapsed' => 5]));
        $this->assertSame('clock_not_paused', $e->apiCode);

        ScorekeeperChangeClock(701, ['action' => 'pause', 'elapsed' => null]);
        $e = $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'pause', 'elapsed' => null]));
        $this->assertSame('clock_not_running', $e->apiCode);
        $this->assertSame(['state' => 'paused', 'elapsed' => 600], ScorekeeperChangeClock(701, ['action' => 'set', 'elapsed' => 600])['data']['clock']);
        $this->assertSame('running', ScorekeeperChangeClock(701, ['action' => 'resume', 'elapsed' => null])['data']['clock']['state']);

        DBQuery("UPDATE uo_game SET homescore=1, visitorscore=0 WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'reset', 'elapsed' => null]));
        $this->assertSame('score_not_zero', $e->apiCode);
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=0 WHERE game_id=701");
        $this->assertSame(['state' => 'not_started', 'elapsed' => 0], ScorekeeperChangeClock(701, ['action' => 'reset', 'elapsed' => null])['data']['clock']);

        DBQuery("UPDATE uo_game SET hasstarted=2, isongoing=0 WHERE game_id=701");
        $this->assertSame('game_final', $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'start', 'elapsed' => null]))->apiCode);
        $this->setSeason("hide_time_on_scoresheet=1");
        $this->assertSame('times_hidden', $this->refusal(fn() => ScorekeeperChangeClock(701, ['action' => 'start', 'elapsed' => null]))->apiCode);
    }

    public function testOnlyTheTeamsLastStoppageIsDeleted(): void
    {
        $this->skipWithoutCorrections();
        ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 100]);
        ScorekeeperAddStoppage(701, 'timeout', ['side' => 'home', 'time' => 200]);
        ScorekeeperAddStoppage(701, 'spirit_stoppage', ['side' => 'away', 'time' => 50]);

        $e = $this->refusal(fn() => ScorekeeperDeleteLastStoppage(701, 'timeout', 'home', 1));
        $this->assertSame([409, 'timeout_changed'], [$e->httpStatus, $e->apiCode]);
        $this->assertSame('timeout_changed', $this->refusal(fn() => ScorekeeperDeleteLastStoppage(701, 'timeout', 'away', 1))->apiCode, 'a team without timeouts');
        $this->assertSame(
            ['status' => 200, 'data' => ['deleted' => ['side' => 'home', 'num' => 2]]],
            ScorekeeperDeleteLastStoppage(701, 'timeout', 'home', 2),
        );
        $this->assertSame([[1, 100]], $this->ints("SELECT num, time FROM uo_timeout WHERE game=701"));
        $this->assertSame('spirit_stoppage_changed', $this->refusal(fn() => ScorekeeperDeleteLastStoppage(701, 'spirit_stoppage', 'home', 1))->apiCode);
        ScorekeeperDeleteLastStoppage(701, 'spirit_stoppage', 'away', 1);
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_spirit_timeout WHERE game=701"));
    }

    public function testGoalCorrectionChecksPlayersAndKeepsTheRest(): void
    {
        $this->skipWithoutCorrections();
        $this->seedRoster();
        ScorekeeperAddGoal(701, $this->goal('home', 803, 802, 60));

        $fixed = ScorekeeperCorrectGoal(701, 1, ['scorer_id' => 802, 'assist_id' => 803]);
        $this->assertSame(
            ['num' => 1, 'side' => 'home', 'scorer_id' => 802, 'assist_id' => 803, 'callahan' => false, 'time' => 60, 'home_score' => 1, 'away_score' => 0],
            $fixed['data']['goal'],
        );
        $this->assertSame(802, ScorekeeperCorrectGoal(701, 1, ['callahan' => true, 'assist_id' => null])['data']['goal']['scorer_id'], 'unsent fields keep their value');

        $e = $this->refusal(fn() => ScorekeeperCorrectGoal(701, 1, ['scorer_id' => 800]));
        $this->assertSame([400, 'validation_failed', ['scorer_id']], [$e->httpStatus, $e->apiCode, array_column($e->details, 'path')]);
        $e = $this->refusal(fn() => ScorekeeperCorrectGoal(701, 1, ['assist_id' => 803]));
        $this->assertSame(['assist_id'], array_column($e->details, 'path'), 'a Callahan has no assist');
        $this->assertSame([404, 'goal_not_found'], [($e = $this->refusal(fn() => ScorekeeperCorrectGoal(701, 7, ['scorer_id' => 802])))->httpStatus, $e->apiCode]);

        $this->setSeason("hide_time_on_scoresheet=1");
        $this->assertNull(ScorekeeperCorrectGoal(701, 1, ['scorer_id' => 803])['data']['goal']['time']);
    }

    public function testGameNoteNeedsTheActorsUser(): void
    {
        $this->skipWithoutCorrections();
        $this->startLink();
        try {
            $e = $this->refusal(fn() => ScorekeeperSetGameNote(701, 'Rain'));
            $this->assertSame([403, 'user_token_required'], [$e->httpStatus, $e->apiCode]);

            ScorekeeperApiActor(9930, 9931, 'admin');
            $this->assertSame(['status' => 200, 'data' => ['note' => 'Rain']], ScorekeeperSetGameNote(701, 'Rain'));
            $this->assertSame('admin', DBQueryToValueUncached("SELECT user_id FROM uo_event_log WHERE category='game' AND source='comments' AND id1='701' ORDER BY event_id DESC LIMIT 1"));
            $this->assertSame(['status' => 200, 'data' => ['note' => null]], ScorekeeperSetGameNote(701, ''));
        } finally {
            $this->finishLink();
            DBQuery("DELETE FROM uo_comment WHERE type=4 AND id='701'");
            DBQuery("DELETE FROM uo_event_log WHERE category='game' AND source='comments' AND id1='701'");
        }
    }

    public function testFinalizeNeedsTheStoredScoreToBeTheOneSent(): void
    {
        $this->skipWithoutPhase2();
        $this->standingsDirty = true;
        $e = $this->refusal(fn() => ScorekeeperFinalize(701, ['home' => 2, 'away' => 1]));
        $this->assertSame([409, 'score_changed'], [$e->httpStatus, $e->apiCode], 'a game with no score cannot be finalized');
        $this->assertSame('0', $this->gameRow()['hasstarted']);

        DBQuery("UPDATE uo_game SET homescore=2, visitorscore=1, hasstarted=1, isongoing=1 WHERE game_id=701");
        $e = $this->refusal(fn() => ScorekeeperFinalize(701, ['home' => 3, 'away' => 1]));
        $this->assertSame('score_changed', $e->apiCode, 'another device recorded a different score');
        $this->assertSame('1', $this->gameRow()['isongoing']);

        $this->assertSame(['status' => 200, 'data' => []], ScorekeeperFinalize(701, ['home' => 2, 'away' => 1]));
        $row = $this->gameRow();
        $this->assertSame(['2', '1', '2', '0'], [$row['homescore'], $row['visitorscore'], $row['hasstarted'], $row['isongoing']]);
    }
}
