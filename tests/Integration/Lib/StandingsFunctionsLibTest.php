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

    public function testCompareTeamsSwissdrawFirstRoundEqualVpMarginUsesScore(): void
    {
        // equal vp+margin, different score → line 194
        $a = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 15, 'spirit' => 5];
        $b = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 9, 'spirit' => 5];
        $this->assertSame(-1, CompareTeamsSwissdraw($a, $b));
    }

    public function testCompareTeamsSwissdrawFirstRoundEqualVpMarginScoreUsesSpirit(): void
    {
        // equal vp+margin+score, different spirit → line 197
        $a = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 13, 'spirit' => 12];
        $b = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 13, 'spirit' => 8];
        $this->assertSame(-1, CompareTeamsSwissdraw($a, $b));
    }

    // --- CompareTeamsSwissdraw: else branch (games != 1) ---

    public function testCompareTeamsSwissdrawElseBranchDifferentGamesCount(): void
    {
        $more  = ['games' => 3, 'vp' => 2, 'oppvp' => 4, 'score' => 20, 'spirit' => 10];
        $fewer = ['games' => 2, 'vp' => 2, 'oppvp' => 4, 'score' => 20, 'spirit' => 10];
        $this->assertSame(-1, CompareTeamsSwissdraw($more, $fewer));
        $this->assertSame(1, CompareTeamsSwissdraw($fewer, $more));
    }

    public function testCompareTeamsSwissdrawElseBranchDifferentVP(): void
    {
        $better = ['games' => 2, 'vp' => 4, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
        $worse  = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawElseBranchDifferentOppVP(): void
    {
        $better = ['games' => 2, 'vp' => 2, 'oppvp' => 5, 'score' => 20, 'spirit' => 10];
        $worse  = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawElseBranchDifferentScore(): void
    {
        $better = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 25, 'spirit' => 10];
        $worse  = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawElseBranchDifferentSpirit(): void
    {
        $better = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 12];
        $worse  = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }

    public function testCompareTeamsSwissdrawElseBranchAllEqualReturnsZero(): void
    {
        $a = ['games' => 2, 'vp' => 2, 'oppvp' => 3, 'score' => 20, 'spirit' => 10];
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

    public function testFindSameRankBreaksWhenTieGroupEnds(): void
    {
        // 3 teams tied at rank 1, then a rank-2 team → the break at line 552 fires.
        $points = [
            ['team' => 300, 'arank' => 1],
            ['team' => 301, 'arank' => 1],
            ['team' => 302, 'arank' => 1],
            ['team' => 303, 'arank' => 2],
        ];
        $sameRank = FindSameRank($points, 1);
        $this->assertCount(3, $sameRank);
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
        // Fixture seeds team 300 at activerank=1 in pool 200; no Resolve* call
        // has run yet at this point in the suite.
        $this->assertEquals(1, TeamPoolStanding(300, 200));
    }

    public function testManualFinalStandingsReturnsArrayForFixtureSeries(): void
    {
        // Fixture has no uo_team_final_standing rows for series 100.
        $this->assertSame([], ManualFinalStandings(100));
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
        // Fixture seeds uo_team_stats rows for series 100 (teams 300, 301).
        $this->assertTrue(SeriesHasArchivedStats(100));
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

    public function testFinalStandingsSeasonStatusCountsPublishedSeries(): void
    {
        // Pre-save complete standings so published++ path is covered.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            self::flushQueryCaches();

            $status = FinalStandingsSeasonStatus('HRN2026');
            $this->assertGreaterThan(0, $status['published']);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testSeriesFinalStandingsReturnsArray(): void
    {
        // No manual standings, and pool 200 has placementpool=0, so
        // SeriesPlacementPoolIds(100) is empty and SeriesRanking(100) returns [].
        $this->assertSame([], SeriesFinalStandings(100));
    }

    public function testSeriesFinalStandingsConfirmedReturnsBool(): void
    {
        $this->assertFalse(SeriesFinalStandingsConfirmed(100));
    }

    public function testSeriesFinalStandingsMapReturnsArray(): void
    {
        $this->assertSame([], SeriesFinalStandingsMap(100));
    }

    public function testSeriesFinalStandingsMapCoversDisqualifiedAndElsePaths(): void
    {
        // disqualified=1 → standings entry = 0 (line 874)
        // standing=0 and not disqualified → falls to else → standings entry = index+1 (line 878)
        DBQuery("INSERT INTO uo_team_final_standing (team_id, series, season, standing, disqualified) VALUES (300, 100, 'HRN2026', 1, 1), (301, 100, 'HRN2026', 0, 0)");
        self::flushQueryCaches();
        try {
            $map = SeriesFinalStandingsMap(100);
            $this->assertSame(0, $map[300]);
            $this->assertGreaterThan(0, $map[301]);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            self::flushQueryCaches();
        }
    }

    public function testTeamSeriesStandingReturnsIntForFixtureTeam(): void
    {
        // No placement pools for series 100, so TeamSeriesStanding falls back to
        // TeamPoolStanding(300, 200), which is 1 (fixture's initial activerank).
        $this->assertSame(1, TeamSeriesStanding(300));
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

    public function testCheckSpecialRankingAppliesRule(): void
    {
        // Insert a special ranking rule for pool 200 (frompool=200, fromplacing=1 → torank=2).
        // Team 300 has activerank=1, so the UPDATE will fire.
        DBQuery("INSERT INTO uo_specialranking (frompool, fromplacing, torank) VALUES (200, 1, 2)");
        self::flushQueryCaches();
        try {
            CheckSpecialRanking(200);
            self::flushQueryCaches();
            // Team 300 should now have activerank=2.
            $newRank = (int) DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=200 AND team=300");
            $this->assertSame(2, $newRank);
        } finally {
            DBQuery("DELETE FROM uo_specialranking WHERE frompool=200");
            // Restore team 300 to activerank=1.
            DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=300");
            self::flushQueryCaches();
        }
    }

    public function testResolveSeriesPoolStandingsUpdatesActiveRank(): void
    {
        $before = $this->seriesTeamIdSnapshot();
        // Swap the fixture's initial ranks first so a correct outcome can only come
        // from actually recomputing from game results (team 300 beat team 301 15-11
        // in game 700), not from the algorithm being a no-op that leaves the
        // fixture's original activerank values (1, 2) untouched.
        DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=300");
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
        self::flushQueryCaches();
        try {
            ResolveSeriesPoolStandings(200);
            self::flushQueryCaches();
            $this->assertEquals(1, TeamPoolStanding(300, 200));
            $this->assertEquals(2, TeamPoolStanding(301, 200));
        } finally {
            DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=300");
            DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
            self::flushQueryCaches();
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
        // Game 700 (started, decided): team 300 beat team 301 15-11.
        // Game 701 has hasstarted=0, so it's excluded.
        $result = getMatchesWins($points, 200);
        $this->assertEquals(1, $result[0]['games']);
        $this->assertEquals(1, $result[0]['wins']);
        $this->assertEquals(0, $result[0]['losses']);
        $this->assertEquals(1, $result[1]['games']);
        $this->assertEquals(0, $result[1]['wins']);
        $this->assertEquals(1, $result[1]['losses']);
    }

    public function testGetMatchesGoalsReturnsArrayForFixtureTeams(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        // Game 700: team 300 (home) scored 15, team 301 (visitor) scored 11.
        $result = getMatchesGoals($points, 200);
        $this->assertSame(15, $result[0]['goalsmade']);
        $this->assertSame(11, $result[0]['goalsagainst']);
        $this->assertSame(4, $result[0]['goalsdiff']);
        $this->assertSame(11, $result[1]['goalsmade']);
        $this->assertSame(15, $result[1]['goalsagainst']);
        $this->assertSame(-4, $result[1]['goalsdiff']);
    }

    public function testGetMatchesWinsSharedReturnsArray(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        // Both fixture teams are in $points, so the shared filter (hometeam/visitorteam
        // IN sameteams) doesn't exclude game 700; result matches the unshared case.
        $result = getMatchesWins($points, 200, true);
        $this->assertEquals(1, $result[0]['games']);
        $this->assertEquals(1, $result[0]['wins']);
        $this->assertEquals(1, $result[1]['games']);
        $this->assertEquals(0, $result[1]['wins']);
    }

    public function testGetMatchesGoalsSharedReturnsArray(): void
    {
        $points = [
            ['team' => 300, 'wins' => 0, 'arank' => 1],
            ['team' => 301, 'wins' => 0, 'arank' => 1],
        ];
        $result = getMatchesGoals($points, 200, true);
        $this->assertSame(15, $result[0]['goalsmade']);
        $this->assertSame(11, $result[1]['goalsmade']);
    }

    // --- FinalStandingLabel ---

    public function testFinalStandingLabelReturnsGoldForFirst(): void
    {
        $this->assertSame('Gold', FinalStandingLabel(1));
    }

    public function testFinalStandingLabelReturnsSilverForSecond(): void
    {
        $this->assertSame('Silver', FinalStandingLabel(2));
    }

    public function testFinalStandingLabelReturnsBronzeForThird(): void
    {
        $this->assertSame('Bronze', FinalStandingLabel(3));
    }

    public function testFinalStandingLabelReturnsOrdinalForFourthPlus(): void
    {
        // GetDefaultLocale() (fixture: 'en_GB.utf8') → ordinal()'s English suffix branch.
        $this->assertSame('4th', FinalStandingLabel(4));
    }

    public function testFinalStandingLabelReturnsUndecidedForZero(): void
    {
        $this->assertSame('Undecided', FinalStandingLabel(0));
    }

    public function testFinalStandingLabelReturnsDisqualifiedLabel(): void
    {
        $this->assertSame('Disqualified', FinalStandingLabel(1, true));
    }

    // --- FinalStandingsAdminOrder ---

    public function testFinalStandingsAdminOrderReturnsTeamsAndSource(): void
    {
        // No manual standings, no season points, no live placement-pool order
        // yet → falls to the count(manualByStanding)===0 default branch.
        $result = FinalStandingsAdminOrder('HRN2026', 100);
        $this->assertSame('teams', $result['source']);
        $this->assertCount(2, $result['teams']);
    }

    public function testFinalStandingsAdminOrderUsesManualSourceAndNullSlot(): void
    {
        // After saving only team 300 at standing=1, standing=2 slot is null (team_id=0).
        // Covers: 'manual' source path AND $ordered[] = null path.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SaveFinalStandingsOrder('HRN2026', 100, [300, 0]);
            self::flushQueryCaches();

            $result = FinalStandingsAdminOrder('HRN2026', 100);
            $this->assertSame('manual', $result['source']);
            // Standing-2 slot is null because team 0 is a placeholder.
            $this->assertNull($result['teams'][1]);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testFinalStandingsAdminOrderUsesSeasonPointsSource(): void
    {
        // With no manual standings but a season round, the source is 'seasonpoints'.
        $roundId = 9902;
        DBQuery("INSERT INTO uo_season_round (round_id, season, series, round_no, name)
                 VALUES ($roundId, 'HRN2026', 100, 1, 'Season Round')");
        self::flushQueryCaches();
        try {
            $result = FinalStandingsAdminOrder('HRN2026', 100);
            $this->assertSame('seasonpoints', $result['source']);
        } finally {
            DBQuery("DELETE FROM uo_season_round WHERE round_id=$roundId");
            self::flushQueryCaches();
        }
    }

    // --- FinalStandingsLiveOrder ---

    public function testFinalStandingsLiveOrderReturnsArray(): void
    {
        // Pool 200 has placementpool=0, so SeriesRanking(100) is empty.
        $order = FinalStandingsLiveOrder(100);
        $this->assertSame([], $order);
    }

    // --- FinalStandingsSeasonPointsOrder (no season points in fixture → returns empty) ---

    public function testFinalStandingsSeasonPointsOrderReturnsEmptyForFixtureSeason(): void
    {
        $teams = SeriesTeams(100);
        // No uo_season_round rows for series 100 → SeasonPointsRounds() is empty.
        $order = FinalStandingsSeasonPointsOrder('HRN2026', 100, $teams);
        $this->assertSame([], $order);
    }

    public function testFinalStandingsSeasonPointsOrderWithRoundDataSortsTeams(): void
    {
        // Insert a season round so SeasonPointsRounds returns non-empty, covering the usort body.
        $roundId = 9901;
        DBQuery("INSERT INTO uo_season_round (round_id, season, series, round_no, name)
                 VALUES ($roundId, 'HRN2026', 100, 1, 'Test Round')");
        self::flushQueryCaches();
        try {
            $teams = SeriesTeams(100);
            $order = FinalStandingsSeasonPointsOrder('HRN2026', 100, $teams);
            // usort ran, result is array of team_ids
            $this->assertIsArray($order);
            $this->assertCount(count($teams), $order);
        } finally {
            DBQuery("DELETE FROM uo_season_round WHERE round_id=$roundId");
            self::flushQueryCaches();
        }
    }

    public function testFinalStandingsSeasonPointsOrderSortsByDifferentTotals(): void
    {
        // team 300 > team 301 in total → usort returns $right <=> $left (line 1254).
        $roundId = 9902;
        DBQuery("INSERT INTO uo_season_round (round_id, season, series, round_no, name)
                 VALUES ($roundId, 'HRN2026', 100, 1, 'TotalsRound')");
        DBQuery("INSERT INTO uo_season_points (round_id, team_id, points) VALUES ($roundId, 300, 10), ($roundId, 301, 5)");
        self::flushQueryCaches();
        try {
            $teams = SeriesTeams(100);
            $order = FinalStandingsSeasonPointsOrder('HRN2026', 100, $teams);
            $this->assertSame(300, $order[0]);
        } finally {
            DBQuery("DELETE FROM uo_season_points WHERE round_id=$roundId");
            DBQuery("DELETE FROM uo_season_round WHERE round_id=$roundId");
            self::flushQueryCaches();
        }
    }

    public function testFinalStandingsSeasonPointsOrderTieBrokenByLastRound(): void
    {
        // Equal totals but 301 scores higher in the last round → line 1260 fires.
        $r1 = 9903; $r2 = 9904;
        DBQuery("INSERT INTO uo_season_round (round_id, season, series, round_no, name)
                 VALUES ($r1, 'HRN2026', 100, 1, 'Rd1'), ($r2, 'HRN2026', 100, 2, 'Rd2')");
        DBQuery("INSERT INTO uo_season_points (round_id, team_id, points)
                 VALUES ($r1, 300, 10), ($r1, 301, 5), ($r2, 300, 5), ($r2, 301, 10)");
        self::flushQueryCaches();
        try {
            $teams = SeriesTeams(100);
            $order = FinalStandingsSeasonPointsOrder('HRN2026', 100, $teams);
            $this->assertSame(301, $order[0]); // 301 wins tie on last-round points
        } finally {
            DBQuery("DELETE FROM uo_season_points WHERE round_id IN ($r1, $r2)");
            DBQuery("DELETE FROM uo_season_round WHERE round_id IN ($r1, $r2)");
            self::flushQueryCaches();
        }
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

    public function testSaveFinalStandingsOrderReturnsFalseForDuplicateTeamIds(): void
    {
        // Passing the same team twice triggers the unique-ID guard (line 1000).
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertFalse(SaveFinalStandingsOrder('HRN2026', 100, [300, 300]));
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
        }
    }

    public function testSaveFinalStandingsOrderSkipsSingleTeamStandingGroup(): void
    {
        // Pre-seed a single-team group (standing=1 for team 301 only) so that
        // $standingGroups[1] has count=1 → the continue at line 1036 fires.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            DBQuery("INSERT INTO uo_team_final_standing (season, series, team_id, standing, disqualified)
                     VALUES ('HRN2026', 100, 301, 1, 0)");
            self::flushQueryCaches();
            $result = SaveFinalStandingsOrder('HRN2026', 100, [300, 301]);
            $this->assertTrue($result);
        } finally {
            DBQuery("DELETE FROM uo_team_final_standing WHERE series=100");
            $_SESSION = [];
            self::flushQueryCaches();
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

            // Flush before ClearFinalStandingsOrder so SeriesHasArchivedStats sees live uo_team_stats rows.
            self::flushQueryCaches();
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
            // t1 beat t2 15-9 in the only completed game; swissdraw sorts the winner first.
            $this->assertSame('1', $rank);
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

    // --- Additional resolve pool coverage ---

    public function testResolvePlayoffPoolStandingsEarlyReturnForEmptyPool(): void
    {
        // Pool with no teams → count($teams) <= 1 → early return at line 41.
        $poolId = 9350;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'PO Empty', '1', 0, 0, 0, 0, 0, 0, 100, 2)");
        self::flushQueryCaches();
        try {
            ResolvePlayoffPoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolvePlayoffPoolStandingsRanksT2WinnerFirst(): void
    {
        // t2 beats t1 → DB UPDATE assigns t2 activerank=1 (lines 72-73).
        $poolId = 9351; $t1 = 9360; $t2 = 9361; $g = 9800;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'PO T2Win', '1', 0, 0, 0, 2, 0, 1, 100, 2)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                 VALUES ($t1, 'PO T1', $poolId, 1, 1, 1, 100, 'P1'), ($t2, 'PO T2', $poolId, 2, 2, 1, 100, 'P2')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                 VALUES ($g, $t2, $t1, 15, 9, 1, 0, 1)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($g, $poolId, 1)");
        self::flushQueryCaches();
        try {
            ResolvePlayoffPoolStandings($poolId);
            self::flushQueryCaches();
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t2");
            $this->assertSame('1', $rank);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id=$g");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolvePlayoffPoolStandingsCallsTeamMoveWhenNoGamesLeft(): void
    {
        // Only 1 played game, no open games → gamesleft=0 → TeamMove (lines 81-82).
        $poolId = 9352; $t1 = 9362; $t2 = 9363; $g = 9801;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'PO AllPlayed', '1', 0, 0, 0, 2, 0, 1, 100, 2)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                 VALUES ($t1, 'PO T3', $poolId, 1, 1, 1, 100, 'P3'), ($t2, 'PO T4', $poolId, 2, 2, 1, 100, 'P4')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                 VALUES ($g, $t1, $t2, 15, 9, 1, 0, 1)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($g, $poolId, 1)");
        self::flushQueryCaches();
        try {
            ResolvePlayoffPoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id=$g");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolvePlayoffPoolStandingsHandlesOddTeamCount(): void
    {
        // 3 teams → odd → BYE team gets last rank; TeamMove called for BYE (lines 87, 89, 91).
        $poolId = 9353; $t1 = 9364; $t2 = 9365; $t3 = 9366; $g1 = 9802; $g2 = 9803;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'PO 3Teams', '1', 0, 0, 0, 3, 0, 1, 100, 2)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                 VALUES ($t1, 'PO T5', $poolId, 1, 1, 1, 100, 'P5'),
                        ($t2, 'PO T6', $poolId, 2, 2, 1, 100, 'P6'),
                        ($t3, 'PO T7', $poolId, 3, 3, 1, 100, 'P7')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank)
                 VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2), ($t3, $poolId, 3, 3)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                 VALUES ($g1, $t1, $t2, 15, 9, 1, 0, 1), ($g2, $t2, $t3, NULL, NULL, 1, 0, 0)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($g1, $poolId, 1), ($g2, $poolId, 1)");
        self::flushQueryCaches();
        try {
            ResolvePlayoffPoolStandings($poolId);
            self::flushQueryCaches();
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t3");
            $this->assertSame('3', $rank);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id IN ($g1, $g2)");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2, $t3)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolveCrossMatchPoolStandingsEarlyReturnForEmptyPool(): void
    {
        // Pool with no teams → count($teams) <= 1 → early return at line 132.
        $poolId = 9354;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'CM Empty', '1', 0, 0, 0, 0, 0, 0, 100, 4)");
        self::flushQueryCaches();
        try {
            ResolveCrossMatchPoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolveCrossMatchPoolStandingsCallsTeamMoveWhenNoGamesLeft(): void
    {
        // t2 beats t1 with no open games → gamesleft=0 → TeamMove (lines 173-174).
        $poolId = 9355; $t1 = 9367; $t2 = 9368; $g = 9804;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'CM NoGamesLeft', '1', 0, 0, 0, 2, 0, 1, 100, 4)");
        DBQuery("INSERT INTO uo_team (team_id, name, pool, rank, activerank, valid, series, abbreviation)
                 VALUES ($t1, 'CM T1', $poolId, 1, 1, 1, 100, 'C1'), ($t2, 'CM T2', $poolId, 2, 2, 1, 100, 'C2')");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($t1, $poolId, 1, 1), ($t2, $poolId, 2, 2)");
        DBQuery("INSERT INTO uo_game (game_id, hometeam, visitorteam, homescore, visitorscore, valid, isongoing, hasstarted)
                 VALUES ($g, $t2, $t1, 15, 9, 1, 0, 1)");
        DBQuery("INSERT INTO uo_game_pool (game, pool, timetable) VALUES ($g, $poolId, 1)");
        self::flushQueryCaches();
        try {
            ResolveCrossMatchPoolStandings($poolId);
            self::flushQueryCaches();
            $rank = DBQueryToValue("SELECT activerank FROM uo_team_pool WHERE pool=$poolId AND team=$t2");
            $this->assertSame('1', $rank);
        } finally {
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_game WHERE game_id=$g");
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_team WHERE team_id IN ($t1, $t2)");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }

    public function testResolveSwissdrawPoolStandingsEarlyReturnForEmptyPool(): void
    {
        // Pool with no teams → count($standings) <= 1 → early return at line 273.
        $poolId = 9356;
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames, played, series, type)
                 VALUES ($poolId, 'SD Empty', '1', 0, 0, 0, 0, 0, 0, 100, 3)");
        self::flushQueryCaches();
        try {
            ResolveSwissdrawPoolStandings($poolId);
            $this->assertTrue(true);
        } finally {
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
            self::flushQueryCaches();
        }
    }
}
