<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SeasonFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('season.functions.php', 'database_only');
        ClearSeasonRuntimeCache();
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    protected function tearDown(): void
    {
        ClearSeasonRuntimeCache();
        LegacyApp::closeDatabaseConnection();
    }

    // --- SeasonSeries / SeasonPools (existing) ---

    public function testSeasonSeriesAndPoolsReadBaselineFixture(): void
    {
        $series = SeasonSeries('HRN2026', true);
        $pools = SeasonPools('HRN2026', true, true);

        $this->assertCount(1, $series);
        $this->assertSame('Open', $series[0]['name']);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['poolname']);
    }

    public function testSeasonSeriesAllIncludesFixtureSeries(): void
    {
        $all = SeasonSeries('HRN2026', false);
        $this->assertNotEmpty($all);
    }

    public function testSeasonPoolsWithoutFilters(): void
    {
        $pools = SeasonPools('HRN2026', false, false);
        $this->assertIsArray($pools);
    }

    // --- SeasonTypes ---

    public function testSeasonTypesReturnsNonEmptyList(): void
    {
        $types = SeasonTypes();
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
        $this->assertContains('outdoor', $types);
    }

    // --- ClearSeasonRuntimeCache ---

    public function testClearSeasonRuntimeCacheClearsWithoutError(): void
    {
        SeasonInfo('HRN2026');
        ClearSeasonRuntimeCache();
        $this->assertIsArray(SeasonInfo('HRN2026'));
    }

    // --- CurrentSeason / CurrentSeasons ---

    public function testCurrentSeasonReturnsValueOrNull(): void
    {
        $result = CurrentSeason();
        $this->assertTrue($result === null || is_string($result));
    }

    public function testCurrentSeasonsReturnsArray(): void
    {
        $this->assertIsArray(CurrentSeasons());
    }

    public function testCurrentSeasonNameReturnsString(): void
    {
        $this->assertIsString(CurrentSeasonName());
    }

    // --- SeasonName / Seasontype ---

    public function testSeasonNameReturnsFixtureSeasonName(): void
    {
        $name = SeasonName('HRN2026');
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    public function testSeasontypeReturnsFixtureType(): void
    {
        $this->assertIsString(Seasontype('HRN2026'));
    }

    // --- SeasonInfo ---

    public function testSeasonInfoReturnsFixtureData(): void
    {
        $info = SeasonInfo('HRN2026');
        $this->assertIsArray($info);
        $this->assertSame('HRN2026', $info['season_id']);
    }

    public function testSeasonInfoReturnsFalseForMissingId(): void
    {
        $this->assertFalse((bool) SeasonInfo('NOSUCHSEASON9999'));
    }

    // --- IsSeasonPublicExternal / RequireSeasonPublicExternal / SeasonHomeTeamMode ---

    public function testIsSeasonPublicExternalReturnsBool(): void
    {
        $this->assertIsBool(IsSeasonPublicExternal('HRN2026'));
    }

    public function testIsSeasonPublicExternalReturnsFalseForEmptyId(): void
    {
        $this->assertFalse(IsSeasonPublicExternal(''));
        $this->assertFalse(IsSeasonPublicExternal(null));
    }

    public function testIsSeasonPublicEventReturnsFalseForEmptyId(): void
    {
        $this->assertFalse(IsSeasonPublicEvent(''));
        $this->assertFalse(IsSeasonPublicEvent(null));
    }

    public function testSeasonHomeTeamModeReturnsInt(): void
    {
        $this->assertIsInt(SeasonHomeTeamMode('HRN2026'));
    }

    // --- isEventReadonly / IsSeasonInMaintenance / CanBypassEventMaintenance ---

    public function testIsEventReadonlyReturnsFalseForFixtureSeason(): void
    {
        $this->assertFalse(isEventReadonly('HRN2026'));
    }

    public function testIsSeasonInMaintenanceReturnsBool(): void
    {
        $this->assertIsBool(IsSeasonInMaintenance('HRN2026'));
    }

    public function testIsSeasonInMaintenanceReturnsFalseForEmptyId(): void
    {
        $this->assertFalse(IsSeasonInMaintenance(''));
    }

    public function testSeasonHomeTeamModeReturnsZeroForUnknownId(): void
    {
        $this->assertSame(0, SeasonHomeTeamMode('NOSEASONXYZ9999'));
    }

    public function testCanBypassEventMaintenanceReturnsBool(): void
    {
        $this->assertIsBool(CanBypassEventMaintenance('HRN2026'));
    }

    // --- MaintenanceSeasonFromView ---

    public function testMaintenanceSeasonFromViewReturnsEmptyForUnknownView(): void
    {
        // Returns "" (empty string) when no GET params and view not in currentSeasonViews
        $this->assertSame('', MaintenanceSeasonFromView('nonexistent_view_xyz'));
    }

    public function testMaintenanceSeasonFromViewWithSeasonGetParam(): void
    {
        $_GET['season'] = 'HRN2026';
        $result = MaintenanceSeasonFromView('pools');
        $this->assertTrue($result === null || is_string($result));
    }

    public function testMaintenanceSeasonFromViewWithPoolGetParam(): void
    {
        $_GET['pool'] = '200';
        $result = MaintenanceSeasonFromView('poolstatus');
        $this->assertTrue($result === null || is_string($result));
    }

    // --- MaintenanceSeasonFromTeam ---

    public function testMaintenanceSeasonFromTeamReturnsEmptyOrNullForMissingTeam(): void
    {
        $result = MaintenanceSeasonFromTeam(99999);
        $this->assertTrue($result === null || $result === '');
    }

    public function testMaintenanceSeasonFromTeamReturnsEmptyForZeroId(): void
    {
        // empty(0) === true → early return "" path.
        $this->assertSame('', MaintenanceSeasonFromTeam(0));
    }

    public function testMaintenanceSeasonFromTeamReturnsSeasonForFixtureTeam(): void
    {
        $result = MaintenanceSeasonFromTeam(300);
        $this->assertSame('HRN2026', $result);
    }

    // --- SeasonExists / SeasonNameExists ---

    public function testSeasonExistsReturnsTrueForFixtureSeason(): void
    {
        $this->assertTrue(SeasonExists('HRN2026'));
    }

    public function testSeasonExistsReturnsFalseForMissingSeason(): void
    {
        $this->assertFalse(SeasonExists('NOSUCHSEASON9999'));
    }

    public function testSeasonNameExistsReturnsTrueForFixtureName(): void
    {
        $name = DBQueryToValue("SELECT name FROM uo_season WHERE season_id='HRN2026'");
        $this->assertTrue(SeasonNameExists((string) $name));
    }

    public function testSeasonNameExistsReturnsFalseForMissingName(): void
    {
        $this->assertFalse(SeasonNameExists('No Such Season Name XYZXYZ9999'));
    }

    // --- Seasons / PublicExternalSeasons / SeasonsAllInfo / SeasonsByType / EnrollSeasons ---

    public function testSeasonsWithNoFilterReturnsResult(): void
    {
        $result = DBFetchAllAssoc(Seasons());
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSeasonsWithFilterReturnsResult(): void
    {
        $result = DBFetchAllAssoc(Seasons(['season.season_id' => 'HRN2026']));
        $this->assertIsArray($result);
    }

    public function testPublicExternalSeasonsReturnsArray(): void
    {
        $this->assertIsArray(PublicExternalSeasons());
    }

    public function testSeasonsAllInfoReturnsArray(): void
    {
        $this->assertIsArray(SeasonsAllInfo());
    }

    public function testSeasonsByTypeReturnsArray(): void
    {
        $this->assertIsArray(SeasonsByType('open'));
    }

    public function testEnrollSeasonsReturnsArray(): void
    {
        $this->assertIsArray(EnrollSeasons());
    }

    // --- SeasonAllPlayers / SeasonMissingPlayerProfilesCount ---

    public function testSeasonAllPlayersReturnsArray(): void
    {
        $this->assertIsArray(SeasonAllPlayers('HRN2026'));
    }

    public function testSeasonReservationsAndMissingProfilesReadFixtureState(): void
    {
        $reservations = SeasonReservations('HRN2026');
        $this->assertCount(2, $reservations);
        $this->assertSame('Harness Field Complex', $reservations[0]['name']);
        $this->assertSame(4, (int) SeasonMissingPlayerProfilesCount('HRN2026'));
    }

    // --- SeasonTeams ---

    public function testSeasonTeamsReturnsFixtureTeams(): void
    {
        $teams = SeasonTeams('HRN2026');
        $this->assertIsArray($teams);
        $this->assertCount(2, $teams);
    }

    public function testSeasonTeamsIncludingInvalidReturnsAll(): void
    {
        $this->assertIsArray(SeasonTeams('HRN2026', false));
    }

    // --- SeasonReservationgroups / SeasonReservationLocations ---

    public function testSeasonReservationgroupsReturnsArray(): void
    {
        $this->assertIsArray(SeasonReservationgroups('HRN2026'));
    }

    public function testSeasonReservationLocationsReturnsArray(): void
    {
        $this->assertIsArray(SeasonReservationLocations('HRN2026'));
    }

    public function testSeasonReservationLocationsWithGroupReturnsArray(): void
    {
        $this->assertIsArray(SeasonReservationLocations('HRN2026', 'field'));
    }

    // --- SeasonGamesNotScheduled / SeasonAllGames ---

    public function testSeasonGamesNotScheduledReturnsArray(): void
    {
        $this->assertIsArray(SeasonGamesNotScheduled('HRN2026'));
    }

    public function testSeasonAllGamesReturnsArray(): void
    {
        $this->assertIsArray(SeasonAllGames('HRN2026'));
    }

    // --- SeasonTeamAdmins / SeasonAccreditationAdmins / SeasonGameAdmins / SeasonSpiritAdmins / SeasonAdmins
    //     These die() without isSuperAdmin or editseason right — require loginAsAdmin ---

    public function testSeasonAdminListingFunctionsAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertIsArray(SeasonTeamAdmins('HRN2026'));
            $this->assertIsArray(SeasonTeamAdmins('HRN2026', true));
            $this->assertIsArray(SeasonAccreditationAdmins('HRN2026'));
            $this->assertIsArray(SeasonAccreditationAdmins('HRN2026', true));
            $this->assertIsArray(SeasonGameAdmins('HRN2026'));
            $this->assertIsArray(SeasonSpiritAdmins('HRN2026'));
            $this->assertIsArray(SeasonAdmins('HRN2026'));
        } finally {
            $_SESSION = [];
        }
    }

    // --- CanDeleteSeason ---

    public function testCanDeleteSeasonReturnsFalseWhenSeriesExist(): void
    {
        $this->assertFalse(CanDeleteSeason('HRN2026'));
    }

    // --- Admin: SetEventReadonly, AddSeason, SetSeason, SetSeasonSpiritSettings, DeleteSeason ---

    public function testSetEventReadonlyTogglesFlag(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SetEventReadonly('HRN2026');
            ClearSeasonRuntimeCache();
            $this->assertTrue(isEventReadonly('HRN2026'));
        } finally {
            DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    private static function minimalSeasonParams(string $name): array
    {
        return [
            'name' => $name,
            'type' => 'outdoor',
            'istournament' => 0,
            'isinternational' => 0,
            'organizer' => '',
            'category' => '',
            'isnationalteams' => 0,
            'starttime' => '2030-01-01',
            'endtime' => '2030-12-31',
            'iscurrent' => 0,
            'enrollopen' => 0,
            'enroll_deadline' => '2030-01-01 00:00:00',
            'spiritmode' => 0,
            'showspiritpoints' => 0,
            'showspiritcomments' => 0,
            'showspiritpointsonlyoncomplete' => 0,
            'lockteamspiritonsubmit' => 0,
            'use_season_points' => 0,
            'hide_time_on_scoresheet' => 0,
            'hometeammode' => 0,
            'event_readonly' => 0,
            'maintenance_mode' => 0,
            'api_public' => 0,
            'timezone' => 'Europe/Helsinki',
        ];
    }

    public function testAddSetDeleteSeasonRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7); // max 10 chars
        try {
            $params = self::minimalSeasonParams('Test Harness Season');
            AddSeason($newId, $params);
            $this->assertTrue(SeasonExists($newId));

            $params['name'] = 'Updated Harness Season';
            SetSeason($newId, $params);
            ClearSeasonRuntimeCache();
            $this->assertSame('Updated Harness Season', SeasonInfo($newId)['name']);

            $this->assertTrue(CanDeleteSeason($newId));
            DeleteSeason($newId);
            $this->assertFalse(SeasonExists($newId));
            $newId = null;
        } finally {
            if ($newId !== null) {
                DBQuery("DELETE FROM uo_season WHERE season_id='" . DBEscapeString($newId) . "'");
            }
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    public function testSetSeasonSpiritSettingsRunsWithoutError(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7);
        try {
            AddSeason($newId, self::minimalSeasonParams('Spirit Test Season'));
            SetSeasonSpiritSettings($newId, [
                'spirit_enabled' => 1,
                'spirit_public' => 0,
            ]);
            $this->assertTrue(SeasonExists($newId));
        } finally {
            if (SeasonExists($newId)) {
                DeleteSeason($newId);
            }
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    // ---- RequireSeasonPublicExternal ----

    public function testRequireSeasonPublicExternalReturnsEarlyForPublicSeason(): void
    {
        // HRN2026 has api_public=1 and public_event=1 → IsSeasonPublicExternal returns true → early return
        RequireSeasonPublicExternal('HRN2026');
        $this->assertTrue(true);
    }

    // ---- EnforceSoftMaintenanceForView ----

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForEmptyView(): void
    {
        EnforceSoftMaintenanceForView('');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForIndexView(): void
    {
        EnforceSoftMaintenanceForView('index');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForAdminView(): void
    {
        EnforceSoftMaintenanceForView('admin/seasons');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForUserView(): void
    {
        EnforceSoftMaintenanceForView('user/profile');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForLoginView(): void
    {
        EnforceSoftMaintenanceForView('login');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewReturnsEarlyForLogoutView(): void
    {
        EnforceSoftMaintenanceForView('logout');
        $this->assertTrue(true);
    }

    public function testEnforceSoftMaintenanceForViewCompletesForNonMaintenancePage(): void
    {
        // Non-special view with no season GET param and SoftMaintenanceMode not loaded
        // → returns without die() since no season in maintenance
        $_GET = [];
        EnforceSoftMaintenanceForView('frontpage');
        $this->assertTrue(true);
    }

    // ---- EnforcePrivateEventAccessForView ----

    public function testEnforcePrivateEventAccessForViewReturnsEarlyForEmptyView(): void
    {
        EnforcePrivateEventAccessForView('');
        $this->assertTrue(true);
    }

    public function testEnforcePrivateEventAccessForViewReturnsEarlyForIndexView(): void
    {
        EnforcePrivateEventAccessForView('index');
        $this->assertTrue(true);
    }

    public function testEnforcePrivateEventAccessForViewReturnsEarlyForFrontpage(): void
    {
        EnforcePrivateEventAccessForView('frontpage');
        $this->assertTrue(true);
    }

    public function testEnforcePrivateEventAccessForViewReturnsEarlyForAdminView(): void
    {
        EnforcePrivateEventAccessForView('admin/pools');
        $this->assertTrue(true);
    }

    public function testEnforcePrivateEventAccessForViewReturnsForPublicSeasonPage(): void
    {
        // With no team1 in GET and no season GET param, seasonId is empty → CanAccessSeason skipped
        $_GET = [];
        EnforcePrivateEventAccessForView('pool');
        $this->assertTrue(true);
    }

    // ---- CanAccessSeason ----

    public function testCanAccessSeasonReturnsTrueForEmptyId(): void
    {
        $this->assertTrue(CanAccessSeason(''));
    }

    public function testCanAccessSeasonReturnsTrueForPublicSeason(): void
    {
        // HRN2026 has public_event=1 → IsSeasonPublicEvent returns true
        $this->assertTrue(CanAccessSeason('HRN2026'));
    }

    public function testCanAccessSeasonReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            // HRN2026 is already public_event but also superadmin → covers the isSuperAdmin path
            // Use a non-public season to force isSuperAdmin check
            $this->assertTrue(CanAccessSeason('NONEXISTENT_SEASON_999'));
        } finally {
            $_SESSION = [];
        }
    }

    // ---- EnrollSeasons ----

    public function testEnrollSeasonsReturnsEmptyForNonExistentSource(): void
    {
        // 'nonexistent' series/pool → no enrollments → returns []
        $result = EnrollSeasons([], 'HRN2026');
        $this->assertIsArray($result);
    }

    // ---- CurrentSeason ----

    public function testCurrentSeasonReturnsConfiguredSeason(): void
    {
        $result = CurrentSeason();
        $this->assertSame('HRN2026', $result);
    }

    public function testCurrentSeasonReturnsSelSeasonWhenSetAndAccessible(): void
    {
        // Covers the isset(selseason) && CanAccessSeason(selseason) → return selseason path.
        $_SESSION['userproperties']['selseason'] = 'HRN2026';
        try {
            $this->assertSame('HRN2026', CurrentSeason());
        } finally {
            unset($_SESSION['userproperties']['selseason']);
        }
    }

    public function testCurrentSeasonReturnsEmptyWhenNoCurrentSeasons(): void
    {
        // Covers the CurrentSeasons() empty → return "" path.
        DBQuery("UPDATE uo_season SET iscurrent=0 WHERE season_id='HRN2026'");
        ClearSeasonRuntimeCache();
        try {
            $result = CurrentSeason();
            $this->assertSame('', $result);
        } finally {
            DBQuery("UPDATE uo_season SET iscurrent=1 WHERE season_id='HRN2026'");
            ClearSeasonRuntimeCache();
        }
    }

    // ---- CurrentSeasonName ----

    public function testCurrentSeasonNameReturnsNameViaSelSeason(): void
    {
        $_SESSION['userproperties']['selseason'] = 'HRN2026';
        try {
            $name = CurrentSeasonName();
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        } finally {
            unset($_SESSION['userproperties']['selseason']);
        }
    }

    // ---- SeasonName / Seasontype ----

    public function testSeasonNameReturnsEmptyForUnknownId(): void
    {
        $this->assertSame('', SeasonName('NOSEASONXYZ9999'));
    }

    public function testSeasontypeReturnsEmptyForUnknownId(): void
    {
        $this->assertSame('', Seasontype('NOSEASONXYZ9999'));
    }

    // ---- CanBypassEventMaintenance ----

    public function testCanBypassEventMaintenanceReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertTrue(CanBypassEventMaintenance('HRN2026'));
        } finally {
            $_SESSION = [];
        }
    }

    // ---- EnrollSeasons ----

    public function testEnrollSeasonsIncludesSeasonWithEnrollOpenSet(): void
    {
        // Temporarily set enrollopen=1 on HRN2026 so the foreach body runs.
        DBQuery("UPDATE uo_season SET enrollopen=1 WHERE season_id='HRN2026'");
        ClearSeasonRuntimeCache();
        try {
            $result = EnrollSeasons();
            $this->assertIsArray($result);
            $this->assertArrayHasKey('HRN2026', $result);
        } finally {
            DBQuery("UPDATE uo_season SET enrollopen=0 WHERE season_id='HRN2026'");
            ClearSeasonRuntimeCache();
        }
    }

    // ---- CanAccessSeason ----

    public function testCanAccessSeasonReturnsFalseWhenNotPublicAndNoSession(): void
    {
        // No superadmin, no session uid, non-public season → return false path.
        // Insert a private season via raw SQL (no user.functions.php needed).
        $tmpId = 'TST' . substr(uniqid(), -7);
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                  istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                  use_season_points, hide_time_on_scoresheet, event_readonly, api_public, timezone, spiritmode,
                  public_event)
                 VALUES ('$tmpId', 'Private Test', '2030-01-01', '2030-12-31', 0, 0, 'outdoor',
                  0, 0, 0, '', '', 0, 0, 0, 0, 0, 'UTC', 0, 0)");
        unset($_SESSION['uid'], $_SESSION['userproperties']['userrole']);
        ClearSeasonRuntimeCache();
        try {
            $result = CanAccessSeason($tmpId);
            $this->assertFalse($result);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='" . DBEscapeString($tmpId) . "'");
            ClearSeasonRuntimeCache();
            $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
        }
    }
}
