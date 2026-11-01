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

    public function testTransactionRefusesAGameTheLinkDoesNotCoverWithoutRunningTheWork(): void
    {
        $this->skipWithoutPhase2();
        $ran = false;
        try {
            ScorekeepingApiTransaction(701, function () use (&$ran) {
                $ran = true;
            });
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
}
