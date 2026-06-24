<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// U_() is defined in translation.functions.php. If SearchFunctionsLibTest (alphabetically
// earlier) already defined a shim, this guard prevents a redeclaration fatal error.
if (!function_exists('U_')) {
    function U_(mixed $name): mixed
    {
        return $name;
    }
}

final class SeriesFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('series.functions.php', 'database_only');
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // Fixture: series_id=100 ('Open'), pool_id=200 ('Pool A'),
    // season='HRN2026', teams 300 ('Helsinki Heat') + 301 ('Tampere Tempest').

    // --- CurrentSeries ---

    public function testCurrentSeriesReturnsFixtureSeriesId(): void
    {
        // CurrentSeries returns the active series_id scalar (or -1 when none)
        $seriesId = CurrentSeries('HRN2026');
        $this->assertSame('100', (string) $seriesId);
    }

    // --- SeriesPools ---

    public function testSeriesPoolsReturnsFixturePool(): void
    {
        $pools = SeriesPools(100);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['name']);
    }

    public function testSeriesPoolsOnlyVisibleFiltersCorrectly(): void
    {
        $all     = SeriesPools(100, false);
        $visible = SeriesPools(100, true);
        $this->assertCount(count($all), $visible);
    }

    public function testSeriesPoolsNoContinuingPoolsFilter(): void
    {
        $pools = SeriesPools(100, false, true);
        $this->assertIsArray($pools);
    }

    public function testSeriesPoolsNoPlacementPoolsFilter(): void
    {
        $pools = SeriesPools(100, false, false, true);
        $this->assertIsArray($pools);
    }

    public function testSeriesPoolsReturnsEmptyForUnknownSeries(): void
    {
        $this->assertSame([], SeriesPools(99999));
    }

    // --- SeriesPlacementPoolIds ---

    public function testSeriesPlacementPoolIdsReturnsArray(): void
    {
        $ids = SeriesPlacementPoolIds(100);
        $this->assertIsArray($ids);
    }

    // --- SeriesTypes ---

    public function testSeriesTypesReturnsNonEmptyList(): void
    {
        $types = SeriesTypes();
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
        $this->assertContains('open', $types);
        $this->assertContains('women', $types);
    }

    // --- SeriesTeams ---

    public function testSeriesTeamsReturnsFixtureTeams(): void
    {
        $teams = SeriesTeams(100);
        $this->assertCount(2, $teams);
        $names = array_column($teams, 'name');
        $this->assertContains('Helsinki Heat', $names);
    }

    public function testSeriesTeamsWithOrderBySeedingReturnsTeams(): void
    {
        $teams = SeriesTeams(100, true);
        $this->assertIsArray($teams);
    }

    public function testSeriesTeamsReturnsEmptyForUnknownSeries(): void
    {
        $this->assertSame([], SeriesTeams(99999));
    }

    // --- SeriesTeamStatsPoints ---

    public function testSeriesTeamStatsPointsReturnsArray(): void
    {
        $stats = SeriesTeamStatsPoints(100);
        $this->assertIsArray($stats);
    }

    // --- SeriesTeamsWithoutPool ---

    public function testSeriesTeamsWithoutPoolReturnsArray(): void
    {
        $teams = SeriesTeamsWithoutPool(100);
        $this->assertIsArray($teams);
    }

    // --- Series() ---

    public function testSeriesWithNoFilterReturnsAllSeries(): void
    {
        // Series() returns a mysqli_result; wrap with DBFetchAllAssoc
        $series = DBFetchAllAssoc(Series());
        $this->assertIsArray($series);
        $this->assertNotEmpty($series);
    }

    public function testSeriesWithSeasonFilterReturnsSeries(): void
    {
        $series = DBFetchAllAssoc(Series(['season' => 'HRN2026']));
        $this->assertIsArray($series);
        $ids = array_column($series, 'series_id');
        $this->assertContains('100', $ids);
    }

    public function testSeriesWithOrderingReturnsCorrectly(): void
    {
        $series = DBFetchAllAssoc(Series(null, ['series.series_id' => 'ASC']));
        $this->assertIsArray($series);
    }

    // --- SeriesAllPlayers ---

    public function testSeriesAllPlayersReturnsArray(): void
    {
        $players = SeriesAllPlayers(100);
        $this->assertIsArray($players);
    }

    // --- SeriesName / SeriesSeasonName ---

    public function testSeriesNameReturnsNameForFixtureSeries(): void
    {
        $name = SeriesName(100);
        $this->assertSame('Open', $name);
    }

    public function testSeriesSeasonNameReturnsSeasonForFixtureSeries(): void
    {
        $name = SeriesSeasonName(100);
        $this->assertIsString($name);
    }

    // --- SeriesSeasonId ---

    public function testSeriesSeasonIdReturnsFixtureSeasonId(): void
    {
        $this->assertSame('HRN2026', SeriesSeasonId(100));
    }

    public function testSeriesSeasonIdReturnsNullForUnknownSeries(): void
    {
        $this->assertNull(SeriesSeasonId(99999));
    }

    // --- CurrentSeries branches ---

    public function testCurrentSeriesReturnsGetParamWhenSet(): void
    {
        $_GET['series'] = '100';
        $result = CurrentSeries('HRN2026');
        $this->assertSame('100', (string) $result);
    }

    public function testCurrentSeriesUsesSessionDivisionWhenMatchesSeries(): void
    {
        $_SESSION['division'] = '100';
        $result = CurrentSeries('HRN2026');
        $this->assertSame('100', (string) $result);
    }

    public function testCurrentSeriesFallsBackWhenSessionDivisionNotInSeries(): void
    {
        $_SESSION['division'] = '99999';
        $result = CurrentSeries('HRN2026');
        // Falls back to first series in the season
        $this->assertNotSame(-1, $result);
    }

    // --- SeriesScoreBoard / SeriesScoreBoardArray (all sorting branches) ---

    public function testSeriesScoreBoardReturnsResult(): void
    {
        // SeriesScoreBoard returns mysqli_result; SeriesScoreBoardArray wraps it
        $arr = SeriesScoreBoardArray(100, 'total', null);
        $this->assertIsArray($arr);
    }

    public function testSeriesScoreBoardWithLimitReturnsResult(): void
    {
        $arr = SeriesScoreBoardArray(100, 'total', 5);
        $this->assertIsArray($arr);
    }

    public function testSeriesScoreBoardGoalSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'goal', null));
    }

    public function testSeriesScoreBoardGoalAvgSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'goalavg', null));
    }

    public function testSeriesScoreBoardPassSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'pass', null));
    }

    public function testSeriesScoreBoardPassAvgSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'passavg', null));
    }

    public function testSeriesScoreBoardGamesSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'games', null));
    }

    public function testSeriesScoreBoardTeamSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'team', null));
    }

    public function testSeriesScoreBoardNameSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'name', null));
    }

    public function testSeriesScoreBoardCallahanSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'callahan', null));
    }

    public function testSeriesScoreBoardTotalAvgSorting(): void
    {
        $this->assertIsArray(SeriesScoreBoardArray(100, 'totalavg', null));
    }

    // --- SeriesDefenseBoard / SeriesDefenseBoardArray ---

    public function testSeriesDefenseBoardReturnsResult(): void
    {
        // SeriesDefenseBoard returns mysqli_result; SeriesDefenseBoardArray wraps it
        $arr = SeriesDefenseBoardArray(100, 'blocks', null);
        $this->assertIsArray($arr);
    }

    public function testSeriesDefenseBoardAllSortings(): void
    {
        foreach (['deftotal', 'games', 'team', 'name', 'callahan', 'default_unknown'] as $sort) {
            $this->assertIsArray(SeriesDefenseBoardArray(100, $sort, null), "Sort: $sort");
        }
    }

    public function testSeriesDefenseBoardWithLimit(): void
    {
        $this->assertIsArray(SeriesDefenseBoardArray(100, 'deftotal', 5));
    }

    public function testSeriesScoreBoardDefaultSort(): void
    {
        // 'unknown_sort' triggers the default branch
        $this->assertIsArray(SeriesScoreBoardArray(100, 'unknown_sort', null));
    }

    // --- SeriesAllGames ---

    public function testSeriesAllGamesReturnsFixtureGames(): void
    {
        $games = SeriesAllGames(100);
        $this->assertIsArray($games);
    }

    // --- SeriesInfo ---

    public function testSeriesInfoReturnsFixtureData(): void
    {
        $info = SeriesInfo(100);
        $this->assertIsArray($info);
        $this->assertSame('Open', $info['name']);
        $this->assertSame('HRN2026', $info['season']);
    }

    // --- CanDeleteSeries ---

    public function testCanDeleteSeriesReturnsFalseWhenPoolsExist(): void
    {
        $this->assertFalse(CanDeleteSeries(100));
    }

    // --- SeriesPoolTemplateSql ---

    public function testSeriesPoolTemplateSqlReturnsNullForNullInput(): void
    {
        $this->assertSame('NULL', SeriesPoolTemplateSql(null));
        $this->assertSame('NULL', SeriesPoolTemplateSql(''));
        $this->assertSame('NULL', SeriesPoolTemplateSql(0));
    }

    public function testSeriesPoolTemplateSqlReturnsNullForNonExistentTemplate(): void
    {
        $this->assertSame('NULL', SeriesPoolTemplateSql(99999));
    }

    // --- Enrolled team functions (require hasEditTeamsRight — superadmin passes) ---

    public function testSeriesEnrolledTeamsReturnsArrayAsAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $teams = SeriesEnrolledTeams(100);
            $this->assertIsArray($teams);
        } finally {
            $_SESSION = [];
        }
    }

    public function testSeriesEnrolledTeamByIdReturnsResultAsAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = SeriesEnrolledTeamById(100, 99999);
            $this->assertFalse((bool) $result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testSeriesEnrolledTeamsByUserReturnsArrayAsAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $teams = SeriesEnrolledTeamsByUser(100, 'admin');
            $this->assertIsArray($teams);
        } finally {
            $_SESSION = [];
        }
    }

    public function testAddAndRemoveEnrolledTeamRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $enrolledId = null;
        try {
            AddSeriesEnrolledTeam(100, 'admin', 'Test Enroll Team', null, 1064);
            $teams = SeriesEnrolledTeams(100);
            foreach ($teams as $t) {
                if ($t['name'] === 'Test Enroll Team') {
                    $enrolledId = (int) $t['id'];
                    break;
                }
            }
            $this->assertNotNull($enrolledId, 'Enrolled team was not found after insert');

            $byId = SeriesEnrolledTeamById(100, $enrolledId);
            $this->assertSame('Test Enroll Team', $byId['name']);

            RemoveSeriesEnrolledTeam(100, 'admin', $enrolledId);
            $afterRemove = SeriesEnrolledTeamById(100, $enrolledId);
            $this->assertFalse((bool) $afterRemove);
            $enrolledId = null;
        } finally {
            if ($enrolledId !== null) {
                DBQuery("DELETE FROM uo_enrolledteam WHERE id=$enrolledId");
            }
            $_SESSION = [];
        }
    }

    // --- Admin functions (AddSeries/SetSeries/DeleteSeries return null on no-permission, no die()) ---

    public function testAddAndDeleteSeriesRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newSeriesId = null;
        try {
            $params = [
                'season' => 'HRN2026',
                'name' => 'Test Series',
                'type' => 'round_robin',
                'ordering' => 99,
                'scoringsystem' => 'default',
                'visible' => 1,
                'continuationseries' => null,
                'tiebreaker' => '',
                'registrationopen' => 0,
                'seriesinfo' => '',
                'defaultpoolsize' => 4,
            ];
            $newSeriesId = AddSeries($params);
            $this->assertIsNumeric($newSeriesId);
            $this->assertGreaterThan(0, $newSeriesId);

            // CanDeleteSeries - no pools yet
            $this->assertTrue(CanDeleteSeries($newSeriesId));

            DeleteSeries($newSeriesId);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_series WHERE series_id=$newSeriesId");
            $this->assertSame('0', $count);
            $newSeriesId = null;
        } finally {
            if ($newSeriesId !== null) {
                DBQuery("DELETE FROM uo_series WHERE series_id=$newSeriesId");
            }
            $_SESSION = [];
        }
    }

    public function testSetSeriesUpdatesFields(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newSeriesId = null;
        try {
            $newSeriesId = AddSeries([
                'season' => 'HRN2026',
                'name' => 'Update Test Series',
                'type' => 'round_robin',
                'ordering' => 98,
                'scoringsystem' => 'default',
                'visible' => 1,
                'continuationseries' => null,
                'tiebreaker' => '',
                'registrationopen' => 0,
                'seriesinfo' => '',
                'defaultpoolsize' => 4,
            ]);
            $params = [
                'series_id' => $newSeriesId,
                'name' => 'Updated Name',
                'type' => 'round_robin',
                'ordering' => 98,
                'scoringsystem' => 'default',
                'visible' => 1,
                'continuationseries' => null,
                'tiebreaker' => '',
                'registrationopen' => 0,
                'seriesinfo' => '',
                'defaultpoolsize' => 4,
            ];
            SetSeries($params);
            $name = DBQueryToValue("SELECT name FROM uo_series WHERE series_id=$newSeriesId");
            $this->assertSame('Updated Name', $name);
        } finally {
            if ($newSeriesId !== null) {
                DBQuery("DELETE FROM uo_series WHERE series_id=$newSeriesId");
            }
            $_SESSION = [];
        }
    }

    public function testSeriesCopyTeamsCopiesTeamsToNewSeries(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $targetSeriesId = null;
        try {
            $targetSeriesId = AddSeries([
                'season' => 'HRN2026',
                'name' => 'Copy Target Series',
                'type' => 'open',
                'ordering' => 96,
                'valid' => 1,
            ]);
            $this->assertIsNumeric($targetSeriesId);
            SeriesCopyTeams((int) $targetSeriesId, 100);
            $teams = SeriesTeams((int) $targetSeriesId);
            $this->assertCount(2, $teams);
        } finally {
            if ($targetSeriesId !== null) {
                DBQuery("DELETE FROM uo_team WHERE series=$targetSeriesId");
                DBQuery("DELETE FROM uo_series WHERE series_id=$targetSeriesId");
            }
            $_SESSION = [];
        }
    }

    // --- ConfirmEnrolledTeam ---
    // Non-superadmin die() branch: untestable per docs/lib-test-deep-coverage.md.

    public function testConfirmEnrolledTeamReturnsNullForNonExistentEnrollment(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = ConfirmEnrolledTeam(100, 999999);
            $this->assertNull($result);
        } finally {
            $_SESSION = [];
        }
    }

    // --- SeriesTeamResponsibles ---
    // Non-season-admin die() branch: untestable per docs/lib-test-deep-coverage.md.

    public function testSeriesTeamResponsiblesReturnsArrayForSeasonAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $_SESSION['uid'] = 'admin';
        // Grant 'admin' editseason right for HRN2026 so getEditSeasons returns it
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'editseason', 'HRN2026')");
        $propId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        try {
            $rows = SeriesTeamResponsibles(100);
            $this->assertIsArray($rows);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE prop_id=$propId");
            $_SESSION = [];
        }
    }

    public function testSetSeriesNameUpdatesName(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newSeriesId = null;
        try {
            $newSeriesId = AddSeries([
                'season' => 'HRN2026',
                'name' => 'Rename Test',
                'type' => 'round_robin',
                'ordering' => 97,
                'scoringsystem' => 'default',
                'visible' => 1,
                'continuationseries' => null,
                'tiebreaker' => '',
                'registrationopen' => 0,
                'seriesinfo' => '',
                'defaultpoolsize' => 4,
            ]);
            SetSeriesName($newSeriesId, 'Renamed Series');
            $name = DBQueryToValue("SELECT name FROM uo_series WHERE series_id=$newSeriesId");
            $this->assertSame('Renamed Series', $name);
        } finally {
            if ($newSeriesId !== null) {
                DBQuery("DELETE FROM uo_series WHERE series_id=$newSeriesId");
            }
            $_SESSION = [];
        }
    }
}
