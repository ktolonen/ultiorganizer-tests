<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// Shims for functions defined outside lib/ that swissdraw.functions.php uses.
if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

final class SwissdrawFunctionsLibTest extends TestCase
{
    // Fixture: pool 200 ('Pool A', type=1, series=100), teams 300 ('Helsinki Heat') and
    // 301 ('Tampere Tempest') with activerank 1 and 2, games 700 (300 vs 301) and 701 (301 vs 300).
    // No uo_moveteams rows in the fixture → pool 200 has no contributing pools.

    private ?int $tempSwissPoolId = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // team_stack transitively loads pool.functions.php → swissdraw.functions.php.
        LegacyApp::loadLibFilesUsingProfile(['swissdraw.functions.php'], 'team_stack');
        // game.functions.php needed for GameResult used by TeamsHavePlayed.
        LegacyApp::requireTopLevelLib('game.functions.php');

        // Disable the file-based persistent cache so every DBQueryToValue call
        // (including SELECT LAST_INSERT_ID()) returns live data, not a stale hit.
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['locale'] = 'en_US';
    }

    protected function tearDown(): void
    {
        if ($this->tempSwissPoolId !== null) {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $this->tempSwissPoolId));
            $this->tempSwissPoolId = null;
        }
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- Helper ---

    private function insertTempPool(string $name): int
    {
        $escapedName = DBEscapeString($name);
        DBQuery("INSERT INTO uo_pool (
            name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames,
            timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore,
            halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
            betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower
        ) VALUES (
            '$escapedName', '99', 0, 0, 0, 100, 1, 2, 0, 70, 35, 15, NULL, NULL, 1, NULL,
            NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL
        )");
        $id = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        if ($id === 0) {
            throw new \RuntimeException("insertTempPool: pool INSERT failed for name='$name'");
        }
        return $id;
    }

    // --- DetectTiesInPreviousPool ---

    public function testDetectTiesInPreviousPoolReturnZeroForPoolWithNoSources(): void
    {
        // Pool 200 has no entries in uo_moveteams → loop is empty → return 0.
        $this->assertSame(0, DetectTiesInPreviousPool(200));
    }

    public function testDetectTiesInPreviousPoolLoopBodyWithNormalContributingPool(): void
    {
        // Create a target pool that has pool 200 as contributing source (no ties, 2 active teams).
        $targetId = $this->insertTempPool('TargetPool');
        try {
            DBQuery(sprintf(
                "INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved) VALUES (200, 1, %d, 1, 0)",
                $targetId
            ));
            // Pool 200: activeteams=2, ties=0 → loop body runs, returns 0 (no issues).
            $this->assertSame(0, DetectTiesInPreviousPool($targetId));
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $targetId));
        }
    }

    public function testDetectTiesInPreviousPoolReturnsTwoForEmptyContributingPool(): void
    {
        // An empty pool (no uo_team_pool rows) as source → activeteams=0 → return 2.
        $emptySourceId = $this->insertTempPool('EmptySource');
        $targetId      = $this->insertTempPool('TargetPool2');
        try {
            DBQuery(sprintf(
                "INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved) VALUES (%d, 1, %d, 1, 0)",
                $emptySourceId,
                $targetId
            ));
            $this->assertSame(2, DetectTiesInPreviousPool($targetId));
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $targetId));
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $emptySourceId));
        }
    }

    public function testDetectTiesInPreviousPoolReturnsOneForTiedContributingPool(): void
    {
        // Set both teams in pool 200 to the same activerank to create a tie.
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
        $targetId = $this->insertTempPool('TargetPool3');
        try {
            DBQuery(sprintf(
                "INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved) VALUES (200, 1, %d, 1, 0)",
                $targetId
            ));
            // Pool 200: activeteams=2, ties=1 → return 1.
            $this->assertSame(1, DetectTiesInPreviousPool($targetId));
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $targetId));
            DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
        }
    }

    // --- AutoResolveTiesInSourcePools ---

    public function testAutoResolveTiesInSourcePoolsRunsWithoutError(): void
    {
        // Pool 200 has no source pools → no-op.
        AutoResolveTiesInSourcePools(200);
        $this->assertTrue(true);
    }

    public function testAutoResolveTiesInSourcePoolsCallsAutoResolveForContributingPool(): void
    {
        // Create a target that has pool 200 as source → AutoResolveTies(200) is called.
        $targetId = $this->insertTempPool('TargetPool4');
        try {
            DBQuery(sprintf(
                "INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved) VALUES (200, 1, %d, 1, 0)",
                $targetId
            ));
            // Should call AutoResolveTies(200) without error; pool 200 ranks remain correct.
            AutoResolveTiesInSourcePools($targetId);
            $rank = (int) DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE team=300 AND pool=200");
            $this->assertSame(1, $rank);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $targetId));
        }
    }

    // --- AutoResolveTies ---

    public function testAutoResolveTiesRunsWithoutErrorForPoolWithCorrectRanks(): void
    {
        // Teams 300 and 301 already have activerank 1 and 2 → nothing to update.
        AutoResolveTies(200);
        $rank = (int) DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE team=300 AND pool=200");
        $this->assertSame(1, $rank);
    }

    public function testAutoResolveTiesFixesDuplicateRanks(): void
    {
        // Create a duplicate-rank scenario: both teams at activerank=1.
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
        AutoResolveTies(200);
        // Team 301 should be pushed to rank 2.
        $rank301 = (int) DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE team=301 AND pool=200");
        $this->assertSame(2, $rank301);
        // Restore.
        DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
    }

    // --- CheckSwissdrawMoves ---

    public function testCheckSwissdrawMovesReturnOneForPoolWithNoMoves(): void
    {
        // Pool 200: no ties, no moves → return 1.
        $this->assertSame(1, CheckSwissdrawMoves(200));
    }

    public function testCheckSwissdrawMovesEarlyReturnNegativeOneTiesDetected(): void
    {
        // Pool 200 with ties as contributing → DetectTiesInPreviousPool returns 1 → early return -1.
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
        $targetId = $this->insertTempPool('SwissCheckTarget');
        try {
            DBQuery(sprintf(
                "INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved) VALUES (200, 1, %d, 1, 0)",
                $targetId
            ));
            $result = CheckSwissdrawMoves($targetId);
            $this->assertSame(-1, $result);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $targetId));
            DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
        }
    }

    // --- TeamsHavePlayed ---

    public function testTeamsHavePlayedReturnsTrueWhenTeamsPlayedGame(): void
    {
        // Game 700: hometeam=300, visitorteam=301.
        $this->assertTrue(TeamsHavePlayed(300, 301, [700]));
    }

    public function testTeamsHavePlayedReturnsTrueForReversedTeams(): void
    {
        // Game 701: hometeam=301, visitorteam=300.
        $this->assertTrue(TeamsHavePlayed(301, 300, [701]));
    }

    public function testTeamsHavePlayedReturnsFalseForEmptyGameList(): void
    {
        $this->assertFalse(TeamsHavePlayed(300, 301, []));
    }

    public function testTeamsHavePlayedReturnsFalseWhenNoMatchingGame(): void
    {
        $this->assertFalse(TeamsHavePlayed(300, 300, [700]));
    }

    // --- MoveTeamToNewPosition ---

    public function testMoveTeamToNewPositionMovesTeamForward(): void
    {
        $moves = [
            ['fromplacing' => 1, 'team_id' => 300],
            ['fromplacing' => 2, 'team_id' => 301],
            ['fromplacing' => 3, 'team_id' => 302],
        ];
        MoveTeamToNewPosition(0, 2, $moves);
        $this->assertSame(300, $moves[2]['team_id']);
        $this->assertSame(301, $moves[0]['team_id']);
    }

    public function testMoveTeamToNewPositionMovesTeamBackward(): void
    {
        $moves = [
            ['fromplacing' => 1, 'team_id' => 300],
            ['fromplacing' => 2, 'team_id' => 301],
            ['fromplacing' => 3, 'team_id' => 302],
        ];
        MoveTeamToNewPosition(2, 0, $moves);
        $this->assertSame(302, $moves[0]['team_id']);
        $this->assertSame(300, $moves[1]['team_id']);
    }

    // --- FindUnplayedTeam ---

    public function testFindUnplayedTeamReturnsPositionWhenFound(): void
    {
        $moves = [['team_id' => 301]];
        // No games played → 301 is unplayed → return position 0.
        $pos = FindUnplayedTeam(300, 0, $moves, [], true);
        $this->assertSame(0, $pos);
    }

    public function testFindUnplayedTeamReturnsNegativeOneWhenAllPlayed(): void
    {
        $moves = [['team_id' => 301]];
        // Game 700 has 300 vs 301 → already played → return -1.
        $pos = FindUnplayedTeam(300, 0, $moves, [700], true);
        $this->assertSame(-1, $pos);
    }

    public function testFindUnplayedTeamReturnsNegativeOneForBackwardOutOfBounds(): void
    {
        $moves = [['team_id' => 301]];
        // Backward, startPos=-2 < stopPos=-1 → early return -1.
        $pos = FindUnplayedTeam(300, -2, $moves, [], false);
        $this->assertSame(-1, $pos);
    }

    public function testFindUnplayedTeamForwardOutOfBoundsReturnNegativeOne(): void
    {
        $moves = [['team_id' => 301]];
        // Forward, startPos > count(moves) → early return -1.
        $pos = FindUnplayedTeam(300, 5, $moves, [], true);
        $this->assertSame(-1, $pos);
    }

    // --- AdjustForDuplicateGames ---

    public function testAdjustForDuplicateGamesReturnsTrueForEmptyMoves(): void
    {
        $moves = [];
        $this->assertTrue(AdjustForDuplicateGames($moves, [], true));
    }

    public function testAdjustForDuplicateGamesReturnsTrueWhenNoDuplicates(): void
    {
        $moves = [
            ['team_id' => 300, 'fromplacing' => 1],
            ['team_id' => 301, 'fromplacing' => 2],
        ];
        // No previous games → no duplicates → return true.
        $this->assertTrue(AdjustForDuplicateGames($moves, [], true));
    }

    public function testAdjustForDuplicateGamesReturnsFalseWhenCannotRearrange(): void
    {
        $moves = [
            ['team_id' => 300, 'fromplacing' => 1],
            ['team_id' => 301, 'fromplacing' => 2],
        ];
        // Teams 300 and 301 played game 700 → FindUnplayedTeam fails → false.
        $this->assertFalse(AdjustForDuplicateGames($moves, [700], true));
    }

    public function testAdjustForDuplicateGamesBackwardDirectionNoDuplicates(): void
    {
        $moves = [
            ['team_id' => 300, 'fromplacing' => 1],
            ['team_id' => 301, 'fromplacing' => 2],
        ];
        // Backward direction with no previous games → true.
        $this->assertTrue(AdjustForDuplicateGames($moves, [], false));
    }

    public function testAdjustForDuplicateGamesSuccessfullyRearrangesWhenThirdTeamAvailable(): void
    {
        // 4 teams: 300 vs 301 played game 700, but team 999 is available.
        // → rearranges teams so 300 is paired with 999 → MoveTeamToNewPosition is called.
        $moves = [
            ['team_id' => 300, 'fromplacing' => 1],
            ['team_id' => 301, 'fromplacing' => 2],
            ['team_id' => 999, 'fromplacing' => 3],
            ['team_id' => 998, 'fromplacing' => 4],
        ];
        $this->assertTrue(AdjustForDuplicateGames($moves, [700], true));
    }

    // --- PrintMoves ---

    public function testPrintMovesOutputsTableForEmptyMoves(): void
    {
        ob_start();
        PrintMoves([]);
        $html = ob_get_clean();
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function testPrintMovesOutputsRowsForNonEmptyMoves(): void
    {
        // PoolTeamFromStandings(200, 1) returns 'Helsinki Heat' (team 300, activerank=1).
        $moves = [[
            'name'          => 'Pool A',
            'frompool'      => 200,
            'fromplacing'   => 1,
            'torank'        => 1,
            'scheduling_id' => null,
            'pteamname'     => 'Slot 1',
        ]];
        ob_start();
        PrintMoves($moves);
        $html = ob_get_clean();
        $this->assertStringContainsString('Helsinki Heat', $html);
    }

    // --- PoolTeamFromStandingsNoTies ---

    public function testPoolTeamFromStandingsNoTiesReturnTeamForValidRank(): void
    {
        $row = PoolTeamFromStandingsNoTies(200, 1);
        $this->assertIsArray($row);
        $this->assertSame('300', (string) $row['team_id']);
    }

    public function testPoolTeamFromStandingsNoTiesSearchbackForTied(): void
    {
        // Set both teams to activerank=1 so rank=2 is empty.
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
        try {
            $row = PoolTeamFromStandingsNoTies(200, 2);
            $this->assertIsArray($row);
            $this->assertNotNull($row['team_id']);
        } finally {
            DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
        }
    }

    // --- CheckBYESchedule ---

    public function testCheckBYEScheduleRunsWithoutErrorForRegularPool(): void
    {
        // Pool 200 has no BYE teams (valid=2) → query returns 0 rows → no swap.
        ob_start();
        CheckBYESchedule(200);
        ob_end_clean();
        $this->assertTrue(true);
    }

    // --- CheckBYE ---

    public function testCheckBYEReturnZeroForNonSwissdrawPool(): void
    {
        // Pool 200 has type=1 → CheckBYE returns 0 without doing anything.
        $this->assertSame(0, CheckBYE(200));
    }

    public function testCheckBYEReturnZeroForSwissdrawPoolWithNoByeGames(): void
    {
        // Create a minimal swissdraw pool (type=3) with no BYE games.
        DBQuery("INSERT INTO uo_pool (
            name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames,
            timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore,
            halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
            betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower
        ) VALUES (
            'TestSwiss', '99', 0, 0, 0, 100, 3, 2, 0, 70, 35, 15, NULL, NULL, 1, NULL,
            NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL
        )");
        $this->tempSwissPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        // No BYE teams → both UPDATEs affect 0 rows → returns 0.
        $this->assertSame(0, CheckBYE($this->tempSwissPoolId));
    }

    // --- CheckPlayoffMoves ---

    public function testCheckPlayoffMovesReturnZeroForEvenTeamCount(): void
    {
        // Pool 200 has 2 teams (even) → is_odd(2)=false → return 0 immediately.
        $this->assertSame(0, CheckPlayoffMoves(200));
    }

    public function testCheckPlayoffMovesWithOddTeamCountAndNoMoves(): void
    {
        // Create pool with teams=1 (odd count) and no moveteams → passes odd check, returns 0.
        DBQuery("INSERT INTO uo_pool (
            name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames,
            timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore,
            halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
            betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower
        ) VALUES (
            'OddPool', '98', 0, 0, 0, 100, 1, 1, 0, 70, 35, 15, NULL, NULL, 1, NULL,
            NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL
        )");
        $oddPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        try {
            // is_odd(1) = true → proceeds past early return, but no moves → foreach empty → return 0.
            $this->assertSame(0, CheckPlayoffMoves($oddPoolId));
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $oddPoolId));
        }
    }

    // --- GenerateSwissdrawPools ---

    public function testGenerateSwissdrawPoolsReturnPoolListWithGenerateFalse(): void
    {
        // rounds=2, generate=false → returns one pool info without DB writes.
        $pools = GenerateSwissdrawPools(200, 2, false);
        $this->assertIsArray($pools);
        $this->assertCount(1, $pools);
        $this->assertStringContainsString('Round 2', $pools[0]['name']);
    }

    public function testGenerateSwissdrawPoolsPseudoteamsPathForEmptyPool(): void
    {
        // Pool with no uo_team_pool entries → first query returns 0 rows → pseudoteams path.
        DBQuery("INSERT INTO uo_pool (
            name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames,
            timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore,
            halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
            betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower
        ) VALUES (
            'EmptyPool', '97', 0, 0, 0, 100, 1, 0, 0, 70, 35, 15, NULL, NULL, 1, NULL,
            NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL
        )");
        $emptyPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        try {
            $pools = GenerateSwissdrawPools($emptyPoolId, 2, false);
            $this->assertIsArray($pools);
            $this->assertCount(1, $pools);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $emptyPoolId));
        }
    }

    public function testCheckSwissdrawMovesReturnOneWithTwoMovesNoGames(): void
    {
        // Two fresh teams with no games between them → AdjustForDuplicateGames
        // finds no conflicts → update loop runs → covers lines 118-165.
        // Uses 2 moves (even count) to avoid the SUT's infinite-loop with odd move counts.
        $srcId = $this->insertTempPool('SwissSrc');
        $targetId = $this->insertTempPool('SwissTarget');
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('SwissA', 1, 100), ('SwissB', 1, 100)");
        $idA = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $idB = $idA + 1;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($idA, $srcId, 1, 1), ($idB, $srcId, 2, 2)");
        PoolAddMove($srcId, $targetId, 1, 1, 'R1Pl1');
        PoolAddMove($srcId, $targetId, 2, 2, 'R1Pl2');
        try {
            $result = CheckSwissdrawMoves($targetId);
            $this->assertSame(1, $result);
        } finally {
            DBQuery("DELETE FROM uo_moveteams WHERE topool=$targetId");
            $schedIds = array_column(DBQueryToArray("SELECT scheduling_id FROM uo_scheduling_name WHERE name IN ('R1Pl1','R1Pl2')"), 'scheduling_id');
            foreach ($schedIds as $sid) { DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$sid"); }
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$srcId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($idA, $idB)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id IN ($srcId, $targetId)");
        }
    }

    public function testCheckBYEScheduleSwapsTimeSlotFromByeToRealGame(): void
    {
        // Set up: 1 BYE game (valid=2 team) with a time, 1 real game without a time.
        // The query returns exactly 2 rows ordered by time (NULL last):
        //   row1 = real game (time=NULL, sorted first)
        //   row2 = BYE game (time set)
        // CheckBYESchedule swaps the slot, covering lines 442-467 (except die() at 456).
        $poolId = 9400;
        $byeTeamId = 9500;
        $byeGameId = 9900;
        $realGameId = 9901;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames, timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower)
                 VALUES ($poolId, 'BYE Pool', '98', 0, 0, 0, 100, 3, 2, 0, 70, 35, 15, NULL, NULL, 0, NULL, NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL)");
        DBQuery("INSERT INTO uo_team (team_id, name, valid, series) VALUES ($byeTeamId, 'BYE', 2, 100)");
        // BYE game: byeTeam (home, valid=2) vs real team 300 (visitor, valid=1), has a time slot.
        // Reservation 500 exists in fixture; needed because reservation is an integer column —
        // DBEscapeString(NULL)='' would fail an integer column if we used NULL here.
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, time, reservation, valid, isongoing, hasstarted)
                 VALUES ($byeGameId, $byeTeamId, 300, '2026-06-01 10:00:00', 500, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($byeGameId, $poolId, 1)");
        // Real game: 300 (valid=1) vs 301 (valid=1), no time slot yet.
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, time, reservation, valid, isongoing, hasstarted)
                 VALUES ($realGameId, 300, 301, NULL, NULL, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($realGameId, $poolId, 1)");
        try {
            ob_start();
            CheckBYESchedule($poolId);
            $output = ob_get_clean();
            $this->assertStringContainsString('Spots swapped', $output);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id IN ($byeGameId, $realGameId)");
            DBQuery("DELETE FROM uo_team WHERE team_id=$byeTeamId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
        }
    }

    public function testGenerateSwissdrawPoolsWithGenerateTrueCreatesNewPool(): void
    {
        // generate=true, rounds=2 → the for loop body (lines 348-363) runs once,
        // creating a new continuation pool with moves and games.
        // Session already has superadmin → hasEditTeamsRight returns true.
        $pools = GenerateSwissdrawPools(200, 2, true);
        $this->assertIsArray($pools);
        $this->assertCount(1, $pools);
        $newPoolId = (int) $pools[0]['pool_id'];
        // Clean up everything created by GenerateSwissdrawPools.
        $gameIds = array_column(
            DBQueryToArray("SELECT game FROM uo_game_pool WHERE pool=$newPoolId"),
            'game'
        );
        DBQuery("DELETE FROM uo_game_pool WHERE pool=$newPoolId");
        if (!empty($gameIds)) {
            $idList = implode(',', array_map('intval', $gameIds));
            DBQuery("DELETE FROM uo_game WHERE game_id IN ($idList)");
        }
        $moveRows = DBQueryToArray("SELECT scheduling_id FROM uo_moveteams WHERE topool=$newPoolId");
        DBQuery("DELETE FROM uo_moveteams WHERE topool=$newPoolId");
        foreach ($moveRows as $row) {
            DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=" . (int) $row['scheduling_id']);
        }
        DBQuery("DELETE FROM uo_pool WHERE pool_id=$newPoolId");
    }

    private function flushQueryCaches(): void
    {
        foreach (['pool_info', 'pool_team', 'db_query_to_value', 'db_query_to_array', 'db_query_to_row', 'db_query_row_count'] as $ns) {
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }
}
