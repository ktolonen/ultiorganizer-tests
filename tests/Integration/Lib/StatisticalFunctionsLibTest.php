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
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
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
        $this->assertNotNull(IsStatsDataAvailable());
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
        $result = SeriesStatisticsByType('open', 'outdoor');
        $this->assertIsArray($result);
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
        $result = PlayerStatistics(800);
        $this->assertIsArray($result);
    }

    // --- AlltimeScoreboard ---

    public function testAlltimeScoreboardReturnsArray(): void
    {
        $result = AlltimeScoreboard('HRN2026', 'open');
        $this->assertIsArray($result);
    }

    // --- ScoreboardAllTime branches ---

    public function testScoreboardAllTimeDefaultSortingReturnsArray(): void
    {
        $result = ScoreboardAllTime(10);
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeWithBothTypeFiltersReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, 'outdoor', 'open');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeWithOnlySeasonTypeReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, 'outdoor', '');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeWithOnlySeriesTypeReturnsArray(): void
    {
        $result = ScoreboardAllTime(10, '', 'open');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeTotalSortingReturnsArray(): void
    {
        $result = ScoreboardAllTime(5, '', '', '', 'total');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeGoalSortingReturnsArray(): void
    {
        $result = ScoreboardAllTime(5, '', '', '', 'goal');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimePassSortingReturnsArray(): void
    {
        $result = ScoreboardAllTime(5, '', '', '', 'pass');
        $this->assertIsArray($result);
    }

    public function testScoreboardAllTimeGamesSortingReturnsArray(): void
    {
        $result = ScoreboardAllTime(5, '', '', '', 'games');
        $this->assertIsArray($result);
    }

    // --- SeasonSpiritTopTeamsBySeriesType ---

    public function testSeasonSpiritTopTeamsBySeriesTypeReturnsArray(): void
    {
        $result = SeasonSpiritTopTeamsBySeriesType('HRN2026', 'open', 3);
        $this->assertIsArray($result);
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
