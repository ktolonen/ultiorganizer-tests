<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class StatisticalFunctionsLibTest extends TestCase
{
    // Fixture: season='HRN2026' (type='outdoor'), series=100 (type='open'),
    // teams 300+301, uo_season_stats row, uo_series_stats row, uo_team_stats rows.

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'game.functions.php', 'pool.functions.php', 'statistical.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        // Prevent getSessionLocale() → GetDefaultLocale() (not loaded).
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        $this->removeSeededPlayerStats();
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // DBQueryToValue/Array/Row/RowCount persistently cache by query string, and
    // $serverConf['PersistentCacheEnabled']='false' is not reliable in a
    // full-suite run, so seed-then-read tests flush explicitly.
    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }

    /**
     * uo_player_stats profile ids seeded by seedPlayerStats(), in the order the
     * three rows are written. uo_player_stats has PRIMARY KEY(player_id) and FKs
     * to uo_player and uo_player_profile, so each row reuses a fixture player and
     * gets its own throwaway profile.
     *
     *   profile 900 (player 800): goals 5, passes 1, callahans 2, games 2 → total 6
     *   profile 901 (player 801): goals 1, passes 4, callahans 1, games 6 → total 5
     *   profile 902 (player 802): goals 2, passes 2, callahans 3, games 4 → total 4
     *
     * Every ScoreboardAllTime sorting produces a different permutation of these
     * three, so an ordering assertion cannot pass under the wrong ORDER BY.
     */
    private const SEEDED_PROFILE_IDS = [900, 901, 902];

    private function seedPlayerStats(): void
    {
        foreach (self::SEEDED_PROFILE_IDS as $profileId) {
            DBQuery(sprintf(
                "INSERT INTO uo_player_profile (profile_id, firstname, lastname)
                 VALUES (%d, 'Stat', 'Profile%d')",
                $profileId,
                $profileId,
            ));
        }

        $rows = [
            // player_id, profile_id, team, games, goals, passes, callahans
            [800, 900, 300, 2, 5, 1, 2],
            [801, 901, 300, 6, 1, 4, 1],
            [802, 902, 301, 4, 2, 2, 3],
        ];
        foreach ($rows as [$playerId, $profileId, $team, $games, $goals, $passes, $callahans]) {
            DBQuery(sprintf(
                "INSERT INTO uo_player_stats
                    (player_id, profile_id, team, season, series, games, goals, passes, callahans)
                 VALUES (%d, %d, %d, 'HRN2026', 100, %d, %d, %d, %d)",
                $playerId,
                $profileId,
                $team,
                $games,
                $goals,
                $passes,
                $callahans,
            ));
        }

        self::flushQueryCaches();
    }

    private function removeSeededPlayerStats(): void
    {
        $ids = implode(',', self::SEEDED_PROFILE_IDS);
        DBQuery("DELETE FROM uo_player_stats WHERE profile_id IN ($ids)");
        DBQuery("DELETE FROM uo_player_profile WHERE profile_id IN ($ids)");
        self::flushQueryCaches();
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function profileOrder(array $rows): array
    {
        return array_map('intval', array_column($rows, 'profile_id'));
    }

    // --- IsSeasonStatsCalculated ---

    public function testIsSeasonStatsCalculatedReturnsTrueForFixtureSeason(): void
    {
        $this->assertTrue(IsSeasonStatsCalculated('HRN2026'));
    }

    public function testIsSeasonStatsCalculatedReturnsFalseForUnknownSeason(): void
    {
        $this->assertFalse(IsSeasonStatsCalculated('NOSUCHSEASON9999'));
    }

    // --- IsStatsDataAvailable ---

    public function testIsStatsDataAvailableReturnsTruthyWhenStatsExist(): void
    {
        // Fixture has a uo_season_stats row, so the SELECT 1 returns 1 (truthy).
        // assertNotNull was too weak — it would also pass on false/0/'' (stats absent).
        $this->assertEquals(1, IsStatsDataAvailable());
    }

    // --- DeleteSeasonStats ---

    public function testDeleteSeasonStatsOnNonExistentSeasonDoesNotThrow(): void
    {
        // Season has no stats rows — deletes 0 rows; permission guard passes (superadmin).
        DeleteSeasonStats('NOSUCHSTAT9999');
        $this->assertTrue(true);
    }

    // --- SeriesStatistics ---

    public function testSeriesStatisticsReturnsArrayForFixtureSeason(): void
    {
        $result = SeriesStatistics('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('100', (string) $result[0]['series_id']);
    }

    public function testSeriesStatisticsReturnsEmptyForUnknownSeason(): void
    {
        $result = SeriesStatistics('NOSEASONFOO');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- SeriesStatisticsByType ---

    public function testSeriesStatisticsByTypeReturnsArrayForKnownTypes(): void
    {
        // Fixture series 100 (type='open') and season HRN2026 (type='outdoor') match, and
        // uo_series_stats has one precomputed row for series 100.
        $result = SeriesStatisticsByType('open', 'outdoor');
        $this->assertCount(1, $result);
        $this->assertSame('100', (string) $result[0]['series_id']);
        $this->assertSame('Open', $result[0]['seriesname']);
        $this->assertEquals(2, $result[0]['teams']);
        $this->assertEquals(2, $result[0]['games']);
        $this->assertEquals(4, $result[0]['players']);
        $this->assertEquals(26, $result[0]['goals_total']);
    }

    public function testSeriesStatisticsByTypeReturnsEmptyForUnknownTypes(): void
    {
        $result = SeriesStatisticsByType('nosuchtype', 'nosuchseasontype');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- ALLSeriesStatistics ---

    public function testALLSeriesStatisticsReturnsArray(): void
    {
        $result = ALLSeriesStatistics();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- SeasonStatistics ---

    public function testSeasonStatisticsReturnsRowForFixtureSeason(): void
    {
        $row = SeasonStatistics('HRN2026');
        $this->assertIsArray($row);
        $this->assertSame('HRN2026', $row['season']);
    }

    public function testSeasonStatisticsReturnsFalseForUnknownSeason(): void
    {
        $this->assertFalse((bool) SeasonStatistics('NOSUCHSEASONFOO'));
    }

    // --- AllSeasonStatistics ---

    public function testAllSeasonStatisticsReturnsArray(): void
    {
        $result = AllSeasonStatistics();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- SeasonTeamStatistics ---

    public function testSeasonTeamStatisticsReturnsArrayForFixtureSeason(): void
    {
        $result = SeasonTeamStatistics('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- TeamStatistics ---

    public function testTeamStatisticsReturnsArrayForFixtureTeam(): void
    {
        $result = TeamStatistics(300);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('300', (string) $result[0]['team_id']);
    }

    public function testTeamStatisticsReturnsEmptyForUnknownTeam(): void
    {
        $result = TeamStatistics(99999);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- TeamStandings ---

    public function testTeamStandingsReturnsTeamsForFixtureSeasonAndType(): void
    {
        $result = TeamStandings('HRN2026', 'open');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- TeamStatisticsByName ---

    public function testTeamStatisticsByNameReturnsArrayForKnownName(): void
    {
        $result = TeamStatisticsByName('Helsinki Heat', 'open');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testTeamStatisticsByNameReturnsEmptyForUnknownName(): void
    {
        $result = TeamStatisticsByName('NoSuchTeamXYZ', 'open');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- PlayerStatistics ---

    public function testPlayerStatisticsReturnsArrayForAnyProfileId(): void
    {
        // Fixture has no uo_player_stats rows at all (populated only by CalcPlayerStats,
        // which the later tests in this class run and clean back up).
        $result = PlayerStatistics(800);
        $this->assertSame([], $result);
    }

    // --- AlltimeScoreboard ---

    public function testAlltimeScoreboardReturnsArray(): void
    {
        // Reads uo_player_stats, which is empty in the fixture.
        $result = AlltimeScoreboard('HRN2026', 'open');
        $this->assertSame([], $result);
    }

    // --- ScoreboardAllTime branches ---

    public function testScoreboardAllTimeDefaultSortingReturnsArray(): void
    {
        // Reads uo_player_stats, which is empty in the fixture, regardless of filters/sorting.
        $result = ScoreboardAllTime(10);
        $this->assertSame([], $result);
    }

    public function testScoreboardAllTimeWithBothTypeFiltersReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, 'outdoor', 'open');
        $this->assertSame([], $result);
    }

    public function testScoreboardAllTimeWithOnlySeasonTypeReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, 'outdoor', '');
        $this->assertSame([], $result);
    }

    public function testScoreboardAllTimeWithOnlySeriesTypeReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, '', 'open');
        $this->assertSame([], $result);
    }

    // ScoreboardAllTime sortings, against seeded uo_player_stats rows. Each
    // sorting yields a different permutation of profiles 900/901/902 (see
    // seedPlayerStats), so these pin the ORDER BY rather than the row count.

    public function testScoreboardAllTimeTotalSortingOrdersByGoalsPlusPasses(): void
    {
        $this->seedPlayerStats();

        $result = ScoreboardAllTime(5, '', '', '', 'total');
        $this->assertSame([900, 901, 902], self::profileOrder($result));
        $this->assertEquals(6, $result[0]['total']);
    }

    public function testScoreboardAllTimeGoalSortingOrdersByGoalsTotal(): void
    {
        $this->seedPlayerStats();

        $result = ScoreboardAllTime(5, '', '', '', 'goal');
        $this->assertSame([900, 902, 901], self::profileOrder($result));
        $this->assertEquals(5, $result[0]['goalstotal']);
    }

    public function testScoreboardAllTimePassSortingOrdersByPassesTotal(): void
    {
        $this->seedPlayerStats();

        $result = ScoreboardAllTime(5, '', '', '', 'pass');
        $this->assertSame([901, 902, 900], self::profileOrder($result));
        $this->assertEquals(4, $result[0]['passestotal']);
    }

    public function testScoreboardAllTimeGamesSortingOrdersByGamesTotal(): void
    {
        $this->seedPlayerStats();

        $result = ScoreboardAllTime(5, '', '', '', 'games');
        $this->assertSame([901, 902, 900], self::profileOrder($result));
        $this->assertEquals(6, $result[0]['gamestotal']);
    }

    public function testScoreboardAllTimeCallahanSortingOrdersByCallahansTotal(): void
    {
        $this->seedPlayerStats();

        // Callahan ordering must differ from every other sorting: profile 902 has
        // the fewest goals+passes and the second-most games, so it can only lead
        // here if callahanstotal is what the query sorts on.
        $result = ScoreboardAllTime(5, '', '', '', 'callahan');
        $this->assertSame([902, 900, 901], self::profileOrder($result));
        $this->assertEquals(3, $result[0]['callahanstotal']);
        $this->assertEquals(2, $result[1]['callahanstotal']);
        $this->assertEquals(1, $result[2]['callahanstotal']);
    }

    public function testScoreboardAllTimeUnknownSortingFallsBackToTotalOrdering(): void
    {
        $this->seedPlayerStats();

        // Contrast against the callahan case above: an unrecognised sorting must
        // hit the default branch, not silently keep the previous ORDER BY.
        $this->assertSame(
            [900, 901, 902],
            self::profileOrder(ScoreboardAllTime(5, '', '', '', 'no-such-sorting')),
        );
        $this->assertSame(
            [902, 900, 901],
            self::profileOrder(ScoreboardAllTime(5, '', '', '', 'callahan')),
        );
    }

    public function testScoreboardAllTimeSumsCallahansAcrossRowsOfTheSameProfile(): void
    {
        // SUM(ps.callahans) grouped by profile: two rows sharing a profile must
        // aggregate, not report only one of them.
        $this->seedPlayerStats();
        DBQuery(
            "INSERT INTO uo_player_stats
                (player_id, profile_id, team, season, series, games, goals, passes, callahans)
             VALUES (803, 902, 301, 'HRN2026', 100, 1, 0, 0, 4)"
        );
        self::flushQueryCaches();

        $result = ScoreboardAllTime(5, '', '', '', 'callahan');
        $this->assertSame(902, (int) $result[0]['profile_id']);
        $this->assertEquals(7, $result[0]['callahanstotal']);

        DBQuery("DELETE FROM uo_player_stats WHERE player_id=803");
        self::flushQueryCaches();
    }

    public function testScoreboardAllTimeLimitCapsResultRows(): void
    {
        $this->seedPlayerStats();

        // Positive contrast: all three seeded profiles are visible without the cap.
        $this->assertCount(3, ScoreboardAllTime(5, '', '', '', 'callahan'));
        $this->assertSame([902], self::profileOrder(ScoreboardAllTime(1, '', '', '', 'callahan')));
    }

    public function testScoreboardAllTimeSeasonAndSeriesTypeFiltersMatchSeededRows(): void
    {
        $this->seedPlayerStats();

        // Seeded rows are in season HRN2026 (type 'outdoor') / series 100 (type
        // 'open'), so the matching filter keeps them and a non-matching one drops
        // them. The empty-result variants above only prove the query runs.
        $this->assertCount(3, ScoreboardAllTime(10, 'outdoor', 'open'));
        $this->assertSame([], ScoreboardAllTime(10, 'indoor', 'open'));
        $this->assertSame([], ScoreboardAllTime(10, 'outdoor', 'women'));
    }

    // --- SeasonSpiritTopTeamsBySeriesType ---

    public function testSeasonSpiritTopTeamsBySeriesTypeReturnsArray(): void
    {
        // Reads uo_team_spirit_stats, which has no fixture rows.
        $result = SeasonSpiritTopTeamsBySeriesType('HRN2026', 'open', 3);
        $this->assertSame([], $result);
    }

    // --- SetTeamSeasonStanding ---

    public function testSetTeamSeasonStandingRunsWithoutError(): void
    {
        // Team 300 has existing uo_team_stats row; UPDATE is idempotent with same standing.
        SetTeamSeasonStanding(300, 1);
        $standing = (int) DBQueryToValue("SELECT standing FROM uo_team_stats WHERE team_id=300");
        $this->assertSame(1, $standing);
    }

    // --- CalcSeasonStats ---

    public function testCalcSeasonStatsRunsWithoutErrorOnFixtureSeason(): void
    {
        // Recalculates stats for HRN2026; values match fixture so update is idempotent.
        CalcSeasonStats('HRN2026');
        $this->assertTrue(IsSeasonStatsCalculated('HRN2026'));
    }

    // --- CalcPlayerStats ---

    public function testCalcPlayerStatsRunsWithoutErrorWhenPlayersHaveNoProfile(): void
    {
        // All fixture players have profile_id=NULL → function skips all, no DB writes.
        CalcPlayerStats('HRN2026');
        $this->assertTrue(true);
    }

    public function testCalcPlayerStatsWritesStatsForPlayerWithProfile(): void
    {
        // Give player 800 (Ari Ace, team 300, played game 700 in HRN2026) a profile_id
        // so CalcPlayerStats covers the INSERT/UPDATE path (lines 452-500).
        DBQuery("INSERT INTO uo_player_profile (email) VALUES ('ari.ace@calcstats.test')");
        $profileId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery("UPDATE uo_player SET profile_id=$profileId WHERE player_id=800");
        try {
            CalcPlayerStats('HRN2026');
            $row = DBQueryToArray("SELECT player_id, games FROM uo_player_stats WHERE player_id=800");
            $this->assertCount(1, $row);
            $this->assertGreaterThan(0, (int) $row[0]['games']);
        } finally {
            DBQuery("DELETE FROM uo_player_stats WHERE player_id=800");
            DBQuery("UPDATE uo_player SET profile_id=NULL WHERE player_id=800");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
        }
    }

    // --- CalcSeriesStats ---

    public function testCalcSeriesStatsRunsWithoutErrorOnFixtureSeason(): void
    {
        CalcSeriesStats('HRN2026');
        $result = SeriesStatistics('HRN2026');
        $this->assertNotEmpty($result);
    }

    // --- CalcTeamStats ---

    public function testCalcTeamStatsRunsWithoutErrorOnFixtureSeason(): void
    {
        CalcTeamStats('HRN2026');
        $result = SeasonTeamStatistics('HRN2026');
        $this->assertNotEmpty($result);
    }
}
