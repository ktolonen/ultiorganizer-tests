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
}
