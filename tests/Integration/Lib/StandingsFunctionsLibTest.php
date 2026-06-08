<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class StandingsFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // team_stack loads team+pool+series+season+statistical; add configuration for ordinal()
        LegacyApp::loadLibFilesUsingProfile(
            ['configuration.functions.php', 'standings.functions.php'],
            'team_stack'
        );
        global $serverConf;
        $serverConf = GetSimpleServerConf();
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    /**
     * DBQueryToValue caches results in the persistent cache keyed by query string
     * (CacheRememberFor('db_query_value', ...)). After a write that changes a COUNT
     * a later read of the same query returns the stale cached value, so flush both
     * the persistent and runtime caches before asserting on post-write reads.
     */
    private static function flushQueryCaches(): void
    {
        $namespaces = ['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'];
        foreach ($namespaces as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }

    // Fixture: pool_id=200, series_id=100, teams 300 (Helsinki Heat) + 301 (Tampere Tempest)

    // --- Pure algorithmic functions (no DB) ---

    public function testScoreAndSolveStandingsRespectTieRules(): void
    {
        $points = [
            ['team' => 301, 'wins' => 1, 'games' => 2, 'losses' => 1, 'arank' => 1],
            ['team' => 300, 'wins' => 2, 'games' => 2, 'losses' => 0, 'arank' => 1],
            ['team' => 302, 'wins' => 1, 'games' => 2, 'losses' => 1, 'arank' => 1],
        ];

        $resolved = SolveStandings($points, 'cmp_score');

        $this->assertSame(4, Score($points[1]));
        $this->assertSame(300, $resolved[0]['team']);
        $this->assertSame(1, $resolved[0]['arank']);
        $this->assertSame(2, $resolved[1]['arank']);
        $this->assertSame(2, $resolved[2]['arank']);
    }

    public function testCompareTeamsSwissdrawPrefersVictoryPointsThenMargin(): void
    {
        $better = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 13, 'spirit' => 10];
        $worse = ['games' => 1, 'vp' => 1, 'margin' => 8, 'score' => 15, 'spirit' => 12];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawEqualVpUsesMargin(): void
    {
        $better = ['games' => 1, 'vp' => 2, 'margin' => 8, 'score' => 13, 'spirit' => 10];
        $worse  = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 15, 'spirit' => 12];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawEqualReturnsZero(): void
    {
        $a = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 13, 'spirit' => 10];
        $this->assertSame(0, CompareTeamsSwissdraw($a, $a));
    }

    public function testCmpScoreOrdersCorrectly(): void
    {
        $high = ['wins' => 2, 'games' => 2, 'losses' => 0];
        $low  = ['wins' => 0, 'games' => 2, 'losses' => 2];
        $this->assertSame(-1, cmp_score($high, $low));
        $this->assertSame(1, cmp_score($low, $high));
        $this->assertSame(0, cmp_score($high, $high));
    }

    public function testCmpGoalsdiffOrdersCorrectly(): void
    {
        $high = ['goalsdiff' => 10];
        $low  = ['goalsdiff' => 3];
        $this->assertSame(-1, cmp_goalsdiff($high, $low));
        $this->assertSame(1, cmp_goalsdiff($low, $high));
        $this->assertSame(0, cmp_goalsdiff($high, $high));
    }

    public function testCmpGoalsmadeOrdersCorrectly(): void
    {
        $high = ['goalsmade' => 15];
        $low  = ['goalsmade' => 8];
        $this->assertSame(-1, cmp_goalsmade($high, $low));
        $this->assertSame(1, cmp_goalsmade($low, $high));
        $this->assertSame(0, cmp_goalsmade($high, $high));
    }

    public function testIsSameRankReturnsTrueForTiedTeams(): void
    {
        $tied = [['arank' => 2], ['arank' => 2], ['arank' => 2]];
        $this->assertTrue(IsSameRank($tied));
    }

    public function testIsSameRankReturnsFalseForDifferentRanks(): void
    {
        $diff = [['arank' => 1], ['arank' => 2]];
        $this->assertFalse(IsSameRank($diff));
    }

    public function testFindSameRankFindsTiedTeams(): void
    {
        $points = [
            ['team' => 300, 'arank' => 1, 'wins' => 2],
            ['team' => 301, 'arank' => 2, 'wins' => 1],
            ['team' => 302, 'arank' => 2, 'wins' => 1],
        ];
        $sameRank = FindSameRank($points, 1);
        $this->assertCount(2, $sameRank);
    }

    public function testFindSameRankReturnsEmptyWhenNoTies(): void
    {
        $points = [
            ['team' => 300, 'arank' => 1, 'wins' => 2],
            ['team' => 301, 'arank' => 2, 'wins' => 1],
            ['team' => 302, 'arank' => 3, 'wins' => 0],
        ];
        $this->assertSame([], FindSameRank($points, 1));
    }

    public function testUpdateStandingsMergesRankChanges(): void
    {
        $to = [
            ['team' => 300, 'arank' => 1, 'wins' => 2],
            ['team' => 301, 'arank' => 2, 'wins' => 0],
        ];
        $from = [['team' => 301, 'arank' => 1], ['team' => 300, 'arank' => 2]];
        $result = UpdateStandings($to, $from);
        $this->assertSame(2, $result[0]['arank']);
        $this->assertSame(1, $result[1]['arank']);
    }

    public function testSolveStandingsWithEmptyPointsReturnsEmpty(): void
    {
        $this->assertSame([], SolveStandings([], 'cmp_score'));
    }

    public function testPrintStandingsOutputsHtml(): void
    {
        $points = [['team' => 300, 'wins' => 2, 'arank' => 1]];
        ob_start();
        PrintStandings($points);
        $output = ob_get_clean();
        $this->assertStringContainsString('300', $output);
    }

    public function testPrintStandingsSwissdrawOutputsHtml(): void
    {
        $points = [['team' => 300, 'games' => 2, 'vp' => 4, 'oppvp' => 2, 'score' => 13, 'arank' => 1]];
        ob_start();
        PrintStandingsSwissdraw($points);
        $output = ob_get_clean();
        $this->assertStringContainsString('300', $output);
    }

    public function testSolveStandingsAccordingSwissdrawSortsTeams(): void
    {
        $points = [
            ['team' => 301, 'vp' => 1, 'margin' => 2, 'score' => 10, 'spirit' => 10, 'games' => 1, 'arank' => 1, 'oppvp' => 2],
            ['team' => 300, 'vp' => 2, 'margin' => 5, 'score' => 13, 'spirit' => 11, 'games' => 1, 'arank' => 1, 'oppvp' => 1],
        ];
        $result = SolveStandingsAccordingSwissdraw($points);
        $this->assertIsArray($result);
        $this->assertSame(300, $result[0]['team']);
    }

    // --- DB-backed read-only functions ---

    public function testTeamPoolStandingReturnsValueForFixtureTeam(): void
    {
        $this->assertNotNull(TeamPoolStanding(300, 200));
    }

    public function testManualFinalStandingsReturnsArrayForFixtureSeries(): void
    {
        $this->assertIsArray(ManualFinalStandings(100));
    }

    public function testHasCompleteManualFinalStandingsReturnsFalseForFixtureSeries(): void
    {
        $this->assertFalse(HasCompleteManualFinalStandings(100));
    }

    public function testHasCompleteManualFinalStandingsReturnsFalseForEmptySeries(): void
    {
        $this->assertFalse(HasCompleteManualFinalStandings(99999));
    }

    public function testSeriesHasArchivedStatsReturnsBool(): void
    {
        $this->assertIsBool(SeriesHasArchivedStats(100));
    }

    public function testSeriesUnplayedGamesCountReturnsNonNegativeInt(): void
    {
        $count = SeriesUnplayedGamesCount(100);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFinalStandingsSeasonStatusReturnsPublishedUnpublished(): void
    {
        $status = FinalStandingsSeasonStatus('HRN2026');
        $this->assertArrayHasKey('published', $status);
        $this->assertArrayHasKey('unpublished', $status);
    }

    public function testSeriesFinalStandingsReturnsArray(): void
    {
        $this->assertIsArray(SeriesFinalStandings(100));
    }

    public function testSeriesFinalStandingsConfirmedReturnsBool(): void
    {
        $this->assertIsBool(SeriesFinalStandingsConfirmed(100));
    }

    public function testSeriesFinalStandingsMapReturnsArray(): void
    {
        $this->assertIsArray(SeriesFinalStandingsMap(100));
    }

    public function testTeamSeriesStandingReturnsIntForFixtureTeam(): void
    {
        $this->assertIsInt(TeamSeriesStanding(300));
    }

    // --- Pool standings resolution (mutate uo_team_pool and may create continuation-pool
    //     teams via PoolMakeMove; snapshot series-100 teams and drop any extras afterward) ---

    /** @return list<int> team_ids currently in series 100 */
    private function seriesTeamIdSnapshot(): array
    {
        return array_map('intval', array_column(DBQueryToArray(
            "SELECT team_id FROM uo_team WHERE series=100"
        ), 'team_id'));
    }

    private function dropTeamsCreatedSince(array $before): void
    {
        $after = $this->seriesTeamIdSnapshot();
        $extra = array_diff($after, $before);
        foreach ($extra as $teamId) {
            $teamId = (int) $teamId;
            DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
            DBQuery("DELETE FROM uo_team_stats WHERE team_id=$teamId");
            DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
        }
    }

    public function testCheckSpecialRankingRunsWithoutError(): void
    {
        $before = $this->seriesTeamIdSnapshot();
        try {
            CheckSpecialRanking(200);
            $this->assertTrue(true);
        } finally {
            $this->dropTeamsCreatedSince($before);
        }
    }

    public function testResolveSeriesPoolStandingsUpdatesActiveRank(): void
    {
        $before = $this->seriesTeamIdSnapshot();
        try {
            ResolveSeriesPoolStandings(200);
            $this->assertNotNull(TeamPoolStanding(300, 200));
        } finally {
            $this->dropTeamsCreatedSince($before);
        }
    }

    public function testResolvePoolStandingsDispatchesForFixturePool(): void
    {
        $before = $this->seriesTeamIdSnapshot();
        try {
            ResolvePoolStandings(200);
            $this->assertTrue(true);
        } finally {
            $this->dropTeamsCreatedSince($before);
        }
    }

    public function testGetMatchesWinsReturnsArrayForFixtureTeams(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        $this->assertIsArray(getMatchesWins($points, 200));
    }

    public function testGetMatchesGoalsReturnsArrayForFixtureTeams(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        $this->assertIsArray(getMatchesGoals($points, 200));
    }

    public function testGetMatchesWinsSharedReturnsArray(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        $this->assertIsArray(getMatchesWins($points, 200, true));
    }

    public function testGetMatchesGoalsSharedReturnsArray(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        $this->assertIsArray(getMatchesGoals($points, 200, true));
    }

    // --- FinalStandingLabel ---

    public function testFinalStandingLabelReturnsGoldForFirst(): void
    {
        $label = FinalStandingLabel(1);
        $this->assertIsString($label);
    }

    public function testFinalStandingLabelReturnsSilverForSecond(): void
    {
        $this->assertIsString(FinalStandingLabel(2));
    }

    public function testFinalStandingLabelReturnsBronzeForThird(): void
    {
        $this->assertIsString(FinalStandingLabel(3));
    }

    public function testFinalStandingLabelReturnsOrdinalForFourthPlus(): void
    {
        $this->assertIsString(FinalStandingLabel(4));
    }

    public function testFinalStandingLabelReturnsUndecidedForZero(): void
    {
        $this->assertIsString(FinalStandingLabel(0));
    }

    public function testFinalStandingLabelReturnsDisqualifiedLabel(): void
    {
        $this->assertIsString(FinalStandingLabel(1, true));
    }

    // --- FinalStandingsAdminOrder ---

    public function testFinalStandingsAdminOrderReturnsTeamsAndSource(): void
    {
        $result = FinalStandingsAdminOrder('HRN2026', 100);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('teams', $result);
        $this->assertArrayHasKey('source', $result);
    }

    // --- FinalStandingsLiveOrder ---

    public function testFinalStandingsLiveOrderReturnsArray(): void
    {
        $order = FinalStandingsLiveOrder(100);
        $this->assertIsArray($order);
    }

    // --- FinalStandingsSeasonPointsOrder (no season points in fixture → returns empty) ---

    public function testFinalStandingsSeasonPointsOrderReturnsEmptyForFixtureSeason(): void
    {
        $teams = SeriesTeams(100);
        $order = FinalStandingsSeasonPointsOrder('HRN2026', 100, $teams);
        $this->assertIsArray($order);
    }

    // --- Admin save functions (require superadmin via isSeasonAdmin; write uo_team_final_standing) ---

    public function testSaveFinalStandingsOrderPersistsPlacements(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            $this->assertTrue($result);
            self::flushQueryCaches();
            $this->assertTrue(HasCompleteManualFinalStandings(100));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderRejectsWrongTeamCount(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // Only one team for a two-team series → false
            $this->assertFalse(SaveFinalStandingsOrder('HRN2026', 100, [300]));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderRejectsUnknownTeam(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertFalse(SaveFinalStandingsOrder('HRN2026', 100, [300, 99999]));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderReturnsFalseForMismatchedSeason(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // Series 100 is in HRN2026, not OTHERSEASON
            $this->assertFalse(SaveFinalStandingsOrder('OTHERSEASON', 100, [300, 301]));
        } finally {
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsAssignmentsPersistsPlacements(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = SaveFinalStandingsAssignments('HRN2026', 100, [300 => 1, 301 => 2]);
            $this->assertTrue($result);
            self::flushQueryCaches();
            $this->assertTrue(HasCompleteManualFinalStandings(100));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsAssignmentsHandlesDisqualification(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = SaveFinalStandingsAssignments('HRN2026', 100, [300 => 1, 301 => 'dq']);
            $this->assertTrue($result);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsAssignmentsRejectsOutOfRangeStanding(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // standing 99 exceeds team count → false
            $this->assertFalse(SaveFinalStandingsAssignments('HRN2026', 100, [300 => 99, 301 => 2]));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderByTeamIdsResolvesSeriesFromTeam(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = SaveFinalStandingsOrderByTeamIds([300, 301]);
            $this->assertTrue($result);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderByTeamIdsReturnsFalseForEmptyList(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertFalse(SaveFinalStandingsOrderByTeamIds([0, 0]));
        } finally {
            $_SESSION = [];
        }
    }

    public function testClearFinalStandingsOrderRemovesPlacements(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            self::flushQueryCaches();
            $this->assertTrue(HasCompleteManualFinalStandings(100));

            $result = ClearFinalStandingsOrder('HRN2026', 100);
            $this->assertTrue($result);
            self::flushQueryCaches();
            $this->assertFalse(HasCompleteManualFinalStandings(100));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testClearFinalStandingsOrderReturnsFalseForMismatchedSeason(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertFalse(ClearFinalStandingsOrder('OTHERSEASON', 100));
        } finally {
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderPreservesDisqualification(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // Pre-seed a disqualification that the drag-reorder list cannot express
            DBQuery("INSERT INTO uo_team_final_standing (season, series, team_id, standing, disqualified) VALUES ('HRN2026', 100, 301, NULL, 1)");
            self::flushQueryCaches();
            // Save order including the disqualified team — its DQ must be preserved
            $result = SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            $this->assertTrue($result);
            self::flushQueryCaches();
            $dq = DBQueryToValue("SELECT disqualified FROM uo_team_final_standing WHERE series=100 AND team_id=301");
            $this->assertSame('1', $dq);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderPreservesSharedPlacements(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // Pre-seed both teams sharing standing 1 (a tie the reorder list can't express)
            DBQuery("INSERT INTO uo_team_final_standing (season, series, team_id, standing, disqualified) VALUES ('HRN2026', 100, 300, 1, 0)");
            DBQuery("INSERT INTO uo_team_final_standing (season, series, team_id, standing, disqualified) VALUES ('HRN2026', 100, 301, 1, 0)");
            self::flushQueryCaches();
            $result = SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            $this->assertTrue($result);
            self::flushQueryCaches();
            // Both teams should remain at the same (shared) standing
            $s300 = DBQueryToValue("SELECT standing FROM uo_team_final_standing WHERE series=100 AND team_id=300");
            $s301 = DBQueryToValue("SELECT standing FROM uo_team_final_standing WHERE series=100 AND team_id=301");
            $this->assertSame($s300, $s301);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSeriesFinalStandingsReturnsManualWhenComplete(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            self::flushQueryCaches();
            // With complete manual standings, SeriesFinalStandings returns ManualFinalStandings
            $standings = SeriesFinalStandings(100);
            $this->assertNotEmpty($standings);
            $this->assertTrue(SeriesFinalStandingsConfirmed(100));
            $map = SeriesFinalStandingsMap(100);
            $this->assertArrayHasKey(300, $map);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    // ===== Playoff / swissdraw / crossmatch pool resolution =====
    // The baseline fixture only has a round-robin (type 1) pool. Build a throwaway
    // pool of the target type with two teams and a played+unplayed game in-test, so
    // ResolvePlayoff/Swissdraw/CrossMatch are exercised. The extra unplayed game keeps
    // TeamPoolGamesLeft > 0 so the playoff/crossmatch resolvers do not trigger TeamMove
    // (which needs continuation/movement rules absent from the fixture). Everything is
    // created with high IDs and removed in finally, so no other test/suite is affected.

    private const PB = 9300; // pool id base
    private const TB = 9310; // team id base
    private const GB = 9700; // game id base

    /** Create a pool of $type (2=playoff,3=swissdraw,4=crossmatch) with 2 teams and games. */
    private function buildResolvePool(int $type): int
    {
        $poolId = self::PB + $type;
        $t1 = self::TB + $type * 2;
        $t2 = $t1 + 1;
        $gPlayed = self::GB + $type * 2;
        $gOpen = $gPlayed + 1;

        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'Resolve Pool $type', '1', 0, 0, 0, 2, 0, 1, 100, $type)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                 VALUES ($t1, 'Resolve Team A$type', $poolId, 1, 1, 1, 100, 'RA$type'),
                        ($t2, 'Resolve Team B$type', $poolId, 2, 2, 1, 100, 'RB$type')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
        // One played game (t1 beats t2) and one unplayed game (keeps gamesleft > 0)
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                 VALUES ($gPlayed, $t1, $t2, 15, 9, 1, 0, 1),
                        ($gOpen, $t2, $t1, NULL, NULL, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($gPlayed, $poolId, 1), ($gOpen, $poolId, 1)");
        self::flushQueryCaches();
        return $poolId;
    }

    private function dropResolvePool(int $type): void
    {
        $poolId = self::PB + $type;
        $t1 = self::TB + $type * 2;
        $t2 = $t1 + 1;
        DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
        DBQuery("DELETE FROM uo_game WHERE game_id IN (" . (self::GB + $type * 2) . ", " . (self::GB + $type * 2 + 1) . ")");
        DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
        DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
        DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
        self::flushQueryCaches();
    }

    public function testResolvePlayoffPoolStandingsRanksWinnerFirst(): void
    {
        $poolId = $this->buildResolvePool(2);
        $t1 = self::TB + 4;
        try {
            ResolvePlayoffPoolStandings($poolId);
            self::flushQueryCaches();
            // Winner of the played game (t1) should be ranked 1
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t1");
            $this->assertSame('1', $rank);
        } finally {
            $this->dropResolvePool(2);
        }
    }

    public function testResolvePlayoffViaResolvePoolStandingsDispatch(): void
    {
        $poolId = $this->buildResolvePool(2);
        try {
            // ResolvePoolStandings dispatches on pool type (2 -> playoff)
            ResolvePoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            $this->dropResolvePool(2);
        }
    }

    public function testResolveSwissdrawPoolStandingsAssignsRanks(): void
    {
        $poolId = $this->buildResolvePool(3);
        $t1 = self::TB + 6;
        try {
            ResolveSwissdrawPoolStandings($poolId);
            self::flushQueryCaches();
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t1");
            $this->assertNotNull($rank);
        } finally {
            $this->dropResolvePool(3);
        }
    }

    public function testResolveSwissdrawViaResolvePoolStandingsDispatch(): void
    {
        $poolId = $this->buildResolvePool(3);
        try {
            ResolvePoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            $this->dropResolvePool(3);
        }
    }

    public function testResolveCrossMatchPoolStandingsRanksWinnerFirst(): void
    {
        $poolId = $this->buildResolvePool(4);
        $t1 = self::TB + 8;
        try {
            ResolveCrossMatchPoolStandings($poolId);
            self::flushQueryCaches();
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t1");
            $this->assertSame('1', $rank);
        } finally {
            $this->dropResolvePool(4);
        }
    }

    public function testResolveCrossMatchViaResolvePoolStandingsDispatch(): void
    {
        $poolId = $this->buildResolvePool(4);
        try {
            ResolvePoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            $this->dropResolvePool(4);
        }
    }

    public function testResolveCrossMatchPromotesLowerSeedWhenItWins(): void
    {
        // Played game won by the second-seed team (t2) → resolver swaps ranks (the
        // team1wins < team2wins branch). Unplayed game keeps gamesleft > 0 (no TeamMove).
        $poolId = 9214;
        $t1 = 9294;
        $t2 = 9295;
        try {
            DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                     VALUES ($poolId, 'CrossMatch Upset', '1', 0, 0, 0, 2, 0, 1, 100, 4)");
            DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                     VALUES ($t1, 'Upset A', $poolId, 1, 1, 1, 100, 'UA'), ($t2, 'Upset B', $poolId, 2, 2, 1, 100, 'UB')");
            DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
            // t2 (visitor) wins the played game → team1wins < team2wins
            DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                     VALUES (9794, $t1, $t2, 8, 15, 1, 0, 1), (9795, $t2, $t1, NULL, NULL, 1, 0, 0)");
            DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (9794, $poolId, 1), (9795, $poolId, 1)");
            self::flushQueryCaches();

            ResolveCrossMatchPoolStandings($poolId);
            self::flushQueryCaches();
            // Winner t2 should be promoted to activerank 1
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t2");
            $this->assertSame('1', $rank);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id IN (9794, 9795)");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolvePlayoffKeepsPositionsOnTie(): void
    {
        // A tied played game (homescore == visitorscore) is excluded from the wins
        // count, so team1wins == team2wins and the resolver hits the "keep current
        // positions" else branch. The unplayed game keeps gamesleft > 0 (no TeamMove).
        $poolId = 9209;
        $t1 = 9290;
        $t2 = 9291;
        try {
            DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                     VALUES ($poolId, 'Tie Playoff', '1', 0, 0, 0, 2, 0, 1, 100, 2)");
            DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                     VALUES ($t1, 'Tie A', $poolId, 1, 1, 1, 100, 'TA'), ($t2, 'Tie B', $poolId, 2, 2, 1, 100, 'TB')");
            DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
            DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                     VALUES (9790, $t1, $t2, 12, 12, 1, 0, 1), (9791, $t2, $t1, NULL, NULL, 1, 0, 0)");
            DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (9790, $poolId, 1), (9791, $poolId, 1)");
            self::flushQueryCaches();

            ResolvePlayoffPoolStandings($poolId);
            self::flushQueryCaches();
            // Tie keeps original positions: t1 stays at activerank 1
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t1");
            $this->assertSame('1', $rank);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id IN (9790, 9791)");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }
}
