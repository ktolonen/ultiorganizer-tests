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
        // Fixture pool 200 has continuingpool=0, so the filter keeps it.
        $pools = SeriesPools(100, false, true);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['name']);
    }

    public function testSeriesPoolsNoPlacementPoolsFilter(): void
    {
        // Fixture pool 200 has placementpool=0, so the filter keeps it.
        $pools = SeriesPools(100, false, false, true);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['name']);
    }

    public function testSeriesPoolsReturnsEmptyForUnknownSeries(): void
    {
        $this->assertSame([], SeriesPools(99999));
    }

    // --- SeriesPlacementPoolIds ---

    public function testSeriesPlacementPoolIdsReturnsArray(): void
    {
        // Fixture pool 200 has placementpool=0, so no placement pools exist.
        $ids = SeriesPlacementPoolIds(100);
        $this->assertSame([], $ids);
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
        // Fixture ranks: team 300=1, team 301=2, so seeding order matches name order here.
        $teams = SeriesTeams(100, true);
        $this->assertCount(2, $teams);
        $this->assertSame('Helsinki Heat', $teams[0]['name']);
        $this->assertSame('Tampere Tempest', $teams[1]['name']);
    }

    public function testSeriesTeamsReturnsEmptyForUnknownSeries(): void
    {
        $this->assertSame([], SeriesTeams(99999));
    }

    // --- SeriesTeamStatsPoints ---

    public function testSeriesTeamStatsPointsReturnsArray(): void
    {
        // Only game 700 has hasstarted>0; game 701 is excluded. Home team 300 won 15-11.
        $stats = SeriesTeamStatsPoints(100);
        $this->assertCount(2, $stats);
        $this->assertEquals(1, $stats['300']['games']);
        $this->assertEquals(1, $stats['300']['wins']);
        $this->assertEquals(0, $stats['300']['losses']);
        $this->assertEquals(15, $stats['300']['scores']);
        $this->assertEquals(11, $stats['300']['against']);
        $this->assertEquals(1, $stats['301']['games']);
        $this->assertEquals(0, $stats['301']['wins']);
        $this->assertEquals(1, $stats['301']['losses']);
        $this->assertEquals(11, $stats['301']['scores']);
        $this->assertEquals(15, $stats['301']['against']);
    }

    // --- SeriesTeamsWithoutPool ---

    public function testSeriesTeamsWithoutPoolReturnsArray(): void
    {
        // Both fixture teams are assigned to pool 200 via uo_team_pool, so none qualify.
        $teams = SeriesTeamsWithoutPool(100);
        $this->assertSame([], $teams);
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
        // Fixture has exactly one series row.
        $series = DBFetchAllAssoc(Series(null, ['series.series_id' => 'ASC']));
        $this->assertCount(1, $series);
        $this->assertSame('100', $series[0]['series_id']);
    }

    // --- SeriesAllPlayers ---

    public function testSeriesAllPlayersReturnsArray(): void
    {
        // 4 fixture players across the 2 fixture teams in series 100.
        $players = SeriesAllPlayers(100);
        $this->assertCount(4, $players);
        $ids = array_column($players, 'player_id');
        sort($ids);
        $this->assertSame(['800', '801', '802', '803'], $ids);
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
        $this->assertSame('Harness Invitational 2026', $name);
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

    // Each fixture player scored exactly 1 goal and assisted exactly 1 goal in game 700
    // (the only game with uo_played rows), so every stat ties across all 4 players and every
    // sorting branch falls through its tie-breaks to lastname ASC: Ace, Blade, North, Twist.
    private function assertSeriesScoreBoardRows(array $arr): void
    {
        $this->assertCount(4, $arr);
        $this->assertSame(['Ace', 'Blade', 'North', 'Twist'], array_column($arr, 'lastname'));
        foreach ($arr as $row) {
            $this->assertEquals(1, $row['done']);
            $this->assertEquals(1, $row['fedin']);
            $this->assertEquals(0, $row['callahan']);
            $this->assertEquals(2, $row['total']);
            $this->assertEquals(1, $row['games']);
        }
    }

    public function testSeriesScoreBoardReturnsResult(): void
    {
        // SeriesScoreBoard returns mysqli_result; SeriesScoreBoardArray wraps it
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'total', null));
    }

    public function testSeriesScoreBoardWithLimitReturnsResult(): void
    {
        $arr = SeriesScoreBoardArray(100, 'total', 5);
        $this->assertSeriesScoreBoardRows($arr);
    }

    public function testSeriesScoreBoardGoalSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'goal', null));
    }

    public function testSeriesScoreBoardGoalAvgSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'goalavg', null));
    }

    public function testSeriesScoreBoardPassSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'pass', null));
    }

    public function testSeriesScoreBoardPassAvgSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'passavg', null));
    }

    public function testSeriesScoreBoardGamesSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'games', null));
    }

    public function testSeriesScoreBoardTeamSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'team', null));
    }

    public function testSeriesScoreBoardNameSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'name', null));
    }

    public function testSeriesScoreBoardCallahanSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'callahan', null));
    }

    public function testSeriesScoreBoardTotalAvgSorting(): void
    {
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'totalavg', null));
    }

    // --- SeriesDefenseBoard / SeriesDefenseBoardArray ---

    // Fixture has no uo_defense rows, so deftotal is 0 for every player and, as with the score
    // board, all tie-breaks fall through to lastname ASC.
    private function assertSeriesDefenseBoardRows(array $arr): void
    {
        $this->assertCount(4, $arr);
        $this->assertSame(['Ace', 'Blade', 'North', 'Twist'], array_column($arr, 'lastname'));
        foreach ($arr as $row) {
            $this->assertEquals(0, $row['deftotal']);
            $this->assertEquals(1, $row['games']);
        }
    }

    public function testSeriesDefenseBoardReturnsResult(): void
    {
        // SeriesDefenseBoard returns mysqli_result; SeriesDefenseBoardArray wraps it.
        // 'blocks' is not a recognized sort key, so it falls through to the default branch.
        $this->assertSeriesDefenseBoardRows(SeriesDefenseBoardArray(100, 'blocks', null));
    }

    public function testSeriesDefenseBoardAllSortings(): void
    {
        foreach (['deftotal', 'games', 'team', 'name', 'callahan', 'default_unknown'] as $sort) {
            $this->assertSeriesDefenseBoardRows(SeriesDefenseBoardArray(100, $sort, null));
        }
    }

    public function testSeriesDefenseBoardWithLimit(): void
    {
        $arr = SeriesDefenseBoardArray(100, 'deftotal', 5);
        $this->assertSeriesDefenseBoardRows($arr);
    }

    public function testSeriesScoreBoardDefaultSort(): void
    {
        // 'unknown_sort' triggers the default branch
        $this->assertSeriesScoreBoardRows(SeriesScoreBoardArray(100, 'unknown_sort', null));
    }

    // --- SeriesAllGames ---

    public function testSeriesAllGamesReturnsFixtureGames(): void
    {
        // Both fixture games (700, 701) are in pool 200's timetable.
        $games = SeriesAllGames(100);
        $this->assertSame(['700', '701'], array_column($games, 'game'));
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
        // Fixture has no uo_enrolledteam rows for series 100.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $teams = SeriesEnrolledTeams(100);
            $this->assertSame([], $teams);
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
        // Fixture has no uo_enrolledteam rows for 'admin' in series 100.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $teams = SeriesEnrolledTeamsByUser(100, 'admin');
            $this->assertSame([], $teams);
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

    public function testConfirmEnrolledTeamCreatesTeamAndSetsStatus(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $enrolledId = null;
        $teamId = null;
        try {
            // Insert enrolled team with no club/country → name-based abbreviation path.
            DBQuery("INSERT INTO uo_enrolledteam (series, userid, name, clubname, countryname, enroll_time, status)
                     VALUES (100, 'admin', 'HarnessEnroll', NULL, NULL, NOW(), 0)");
            $enrolledId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

            $teamId = ConfirmEnrolledTeam(100, $enrolledId);
            $this->assertIsInt($teamId);
            $this->assertGreaterThan(0, $teamId);

            // Enrolled team status should be 1 now.
            $status = (int) DBQueryToValue("SELECT status FROM uo_enrolledteam WHERE id=$enrolledId");
            $this->assertSame(1, $status);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            if ($enrolledId !== null) {
                DBQuery("DELETE FROM uo_enrolledteam WHERE id=$enrolledId");
            }
            $_SESSION = [];
        }
    }

    public function testConfirmEnrolledTeamWithCountryCoversCountryAbbreviation(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $enrolledId = null;
        $teamId = null;
        try {
            // 'Afghanistan' has country_id=1000, abbreviation='AFG' in uo_country.
            DBQuery("INSERT INTO uo_enrolledteam (series, userid, name, clubname, countryname, enroll_time, status)
                     VALUES (100, 'admin', 'HarnessCountry', NULL, 'Afghanistan', NOW(), 0)");
            $enrolledId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

            $teamId = ConfirmEnrolledTeam(100, $enrolledId);
            $this->assertIsInt($teamId);

            // Team abbreviation should be set from country abbreviation (AFG).
            $abb = DBQueryToValue("SELECT abbreviation FROM uo_team WHERE team_id=$teamId");
            $this->assertSame('AFG', $abb);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            if ($enrolledId !== null) {
                DBQuery("DELETE FROM uo_enrolledteam WHERE id=$enrolledId");
            }
            $_SESSION = [];
        }
    }

    public function testRemoveSeriesEnrolledTeamBySelfWithoutAdminRight(): void
    {
        // Covers the !hasEditTeamsRight inner branch (self-delete path).
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $enrolledId = null;
        try {
            DBQuery("INSERT INTO uo_enrolledteam (series, userid, name, clubname, countryname, enroll_time, status)
                     VALUES (100, 'admin', 'SelfDeleteEnroll', NULL, NULL, NOW(), 0)");
            $enrolledId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
            $_SESSION = [];
        } finally {
            // Reset session between setup and the actual test call.
        }

        // Now act as admin user WITHOUT superadmin right, with matching uid.
        LegacyApp::loadUserFunctions();
        $_SESSION['uid'] = 'admin';
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        try {
            RemoveSeriesEnrolledTeam(100, 'admin', $enrolledId);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_enrolledteam WHERE id=$enrolledId");
            $this->assertSame(0, $count);
            $enrolledId = null;
        } finally {
            if ($enrolledId !== null) {
                DBQuery("DELETE FROM uo_enrolledteam WHERE id=$enrolledId");
            }
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
            // No uo_userproperties 'teamadmin:<id>' rows exist in the fixture, so this is empty
            // even though 'admin' has editseason rights (the SUT distinguishes season-admin
            // rights, which gate access, from teamadmin properties, which populate the rows).
            $rows = SeriesTeamResponsibles(100);
            $this->assertSame([], $rows);
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
