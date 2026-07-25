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
        // No visible/valid filter → still just the fixture's one pool.
        $pools = SeasonPools('HRN2026', false, false);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['poolname']);
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
        $this->assertSame('Harness Invitational 2026', SeasonInfo('HRN2026')['name']);
    }

    // --- CurrentSeason / CurrentSeasons ---

    public function testCurrentSeasonReturnsValueOrNull(): void
    {
        $result = CurrentSeason();
        $this->assertTrue($result === null || is_string($result));
    }

    public function testCurrentSeasonsReturnsArray(): void
    {
        // Fixture has exactly one iscurrent=1 season.
        $seasons = CurrentSeasons();
        $this->assertCount(1, $seasons);
        $this->assertSame('HRN2026', $seasons[0]['season_id']);
    }

    public function testCurrentSeasonNameReturnsString(): void
    {
        $this->assertSame('Harness Invitational 2026', CurrentSeasonName());
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
        // uo_season.type (event type: outdoor/indoor/...), distinct from uo_series.type
        // (division type: open/womens/...), which the fixture sets to 'outdoor'.
        $this->assertSame('outdoor', Seasontype('HRN2026'));
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
        // Fixture sets both public_event=1 and api_public=1 for HRN2026.
        $this->assertTrue(IsSeasonPublicExternal('HRN2026'));
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
        // uo_season.hometeammode defaults to 0; the fixture never sets it.
        $this->assertSame(0, SeasonHomeTeamMode('HRN2026'));
    }

    // --- isEventReadonly / IsSeasonInMaintenance / CanBypassEventMaintenance ---

    public function testIsEventReadonlyReturnsFalseForFixtureSeason(): void
    {
        $this->assertFalse(isEventReadonly('HRN2026'));
    }

    public function testIsSeasonInMaintenanceReturnsBool(): void
    {
        // uo_season.maintenance_mode defaults to 0; the fixture never sets it.
        $this->assertFalse(IsSeasonInMaintenance('HRN2026'));
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
        // setUp() only loads season.functions.php ('database_only'), so isSuperAdmin/
        // isSeasonAdmin (defined in user.functions.php) aren't even loaded → both
        // function_exists checks fail → false, regardless of session state.
        $this->assertFalse(CanBypassEventMaintenance('HRN2026'));
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
        $this->assertCount(1, $result);
    }

    public function testPublicExternalSeasonsReturnsArray(): void
    {
        // Fixture's only season has public_event=1 and api_public=1.
        $result = PublicExternalSeasons();
        $this->assertCount(1, $result);
        $this->assertSame('HRN2026', $result[0]['season_id']);
    }

    public function testSeasonsAllInfoReturnsArray(): void
    {
        // Fixture has exactly 1 season.
        $this->assertCount(1, SeasonsAllInfo());
    }

    public function testSeasonsByTypeReturnsArray(): void
    {
        // uo_season.type is 'outdoor' in the fixture, not 'open' (that's the series
        // type); 'open' matches no season.
        $this->assertSame([], SeasonsByType('open'));
        $this->assertCount(1, SeasonsByType('outdoor'));
    }

    public function testEnrollSeasonsReturnsArray(): void
    {
        // Fixture's season has enrollopen=0.
        $this->assertSame([], EnrollSeasons());
    }

    // --- SeasonAllPlayers / SeasonMissingPlayerProfilesCount ---

    public function testSeasonAllPlayersReturnsArray(): void
    {
        // Fixture has 4 players across the season's 2 teams.
        $this->assertCount(4, SeasonAllPlayers('HRN2026'));
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
        // Fixture has exactly 2 teams in this season.
        $this->assertCount(2, SeasonTeams('HRN2026', false));
    }

    // --- SeasonReservationgroups / SeasonReservationLocations ---

    public function testSeasonReservationgroupsReturnsArray(): void
    {
        // Both fixture reservations (500, 501) share one reservationgroup.
        $result = SeasonReservationgroups('HRN2026');
        $this->assertCount(1, $result);
        $this->assertSame('Harness Invitational 2026', $result[0]['reservationgroup']);
    }

    public function testSeasonReservationgroupsOrdersByEarliestStarttimeNotName(): void
    {
        // 'Zulu Group' starts before the fixture group (10:00) and 'Alpha Group'
        // starts after it, so alphabetical order (Alpha, Harness, Zulu) and
        // chronological order (Zulu, Harness, Alpha) disagree. This pins the
        // `ORDER BY MIN(pr.starttime), pr.reservationgroup` behavior added in #82.
        try {
            DBQuery("INSERT INTO uo_reservation (location, fieldname, reservationgroup, starttime, endtime, season, date)
                VALUES (400, '1', 'Zulu Group', '2026-06-01 08:00:00', '2026-06-01 09:00:00', 'HRN2026', '2026-06-01 00:00:00')");
            DBQuery("INSERT INTO uo_reservation (location, fieldname, reservationgroup, starttime, endtime, season, date)
                VALUES (400, '1', 'Alpha Group', '2026-06-01 20:00:00', '2026-06-01 21:00:00', 'HRN2026', '2026-06-01 00:00:00')");

            $groups = array_column(SeasonReservationgroups('HRN2026'), 'reservationgroup');

            $this->assertSame(['Zulu Group', 'Harness Invitational 2026', 'Alpha Group'], $groups);
        } finally {
            DBQuery("DELETE FROM uo_reservation WHERE reservationgroup IN ('Zulu Group', 'Alpha Group')");
        }
    }

    public function testSeasonReservationLocationsReturnsArray(): void
    {
        // DISTINCT is over (location, name, fieldname); the two fixture reservations
        // share a location but have different fieldnames ('1' and '2') → 2 rows.
        $result = SeasonReservationLocations('HRN2026');
        $this->assertCount(2, $result);
        $this->assertSame('1', $result[0]['fieldname']);
        $this->assertSame('2', $result[1]['fieldname']);
    }

    public function testSeasonReservationLocationsWithGroupReturnsArray(): void
    {
        // 'field' matches no fixture reservationgroup (which is
        // 'Harness Invitational 2026') → only exercises the group-filter branch.
        $this->assertSame([], SeasonReservationLocations('HRN2026', 'field'));
    }

    // --- SeasonGamesNotScheduled / SeasonAllGames ---

    public function testSeasonGamesNotScheduledReturnsArray(): void
    {
        // Both fixture games have a time and a reservation, so neither is "unscheduled".
        $this->assertSame([], SeasonGamesNotScheduled('HRN2026'));
    }

    public function testSeasonAllGamesReturnsArray(): void
    {
        // Fixture has exactly 2 games (700, 701) in this season.
        $this->assertCount(2, SeasonAllGames('HRN2026'));
    }

    // --- SeasonTeamAdmins / SeasonAccreditationAdmins / SeasonGameAdmins / SeasonSpiritAdmins / SeasonAdmins
    //     These die() without isSuperAdmin or editseason right — require loginAsAdmin ---

    public function testSeasonAdminListingFunctionsAsSuperAdmin(): void
    {
        // Fixture's only uo_userproperties row is ('admin','userrole','superadmin') —
        // no 'teamadmin:'/'accradmin:'/'gameadmin:'/'spiritadmin:'/'seasonadmin:'
        // entries — so every one of these role-listing queries is empty.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertSame([], SeasonTeamAdmins('HRN2026'));
            $this->assertSame([], SeasonTeamAdmins('HRN2026', true));
            $this->assertSame([], SeasonAccreditationAdmins('HRN2026'));
            $this->assertSame([], SeasonAccreditationAdmins('HRN2026', true));
            $this->assertSame([], SeasonGameAdmins('HRN2026'));
            $this->assertSame([], SeasonSpiritAdmins('HRN2026'));
            $this->assertSame([], SeasonAdmins('HRN2026'));
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
            'showgamecomments' => 0,
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

    public function testAddSeasonPersistsShowgamecommentsFlag(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7);
        try {
            $params = self::minimalSeasonParams('Game Comments Season');
            $params['showgamecomments'] = 1;
            AddSeason($newId, $params);
            ClearSeasonRuntimeCache();
            $this->assertEquals(1, SeasonInfo($newId)['showgamecomments']);

            $params['showgamecomments'] = 0;
            SetSeason($newId, $params);
            ClearSeasonRuntimeCache();
            $this->assertEquals(0, SeasonInfo($newId)['showgamecomments']);
        } finally {
            if (SeasonExists($newId)) {
                DeleteSeason($newId);
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

    public function testSetSeasonSpiritSettingsPersistsShowspiritcommentstoteamsFlag(): void
    {
        // testSetSeasonSpiritSettingsRunsWithoutError above passes
        // spirit_enabled/spirit_public, neither of which SetSeasonSpiritSettings
        // reads (its real keys are spiritmode/showspiritpoints/showspiritcomments/
        // showspiritcommentstoteams/showspiritpointsonlyoncomplete/
        // lockteamspiritonsubmit) — so that test never pins a persisted value.
        // This one uses the real keys and asserts the round trip both ways.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7);
        try {
            AddSeason($newId, self::minimalSeasonParams('Spirit Comments Season'));

            SetSeasonSpiritSettings($newId, [
                'spiritmode' => 1003,
                'showspiritpoints' => 1,
                'showspiritcomments' => 1,
                'showspiritcommentstoteams' => 1,
                'showspiritpointsonlyoncomplete' => 0,
                'lockteamspiritonsubmit' => 0,
            ]);
            ClearSeasonRuntimeCache();
            $this->assertEquals(1, SeasonInfo($newId)['showspiritcommentstoteams']);

            SetSeasonSpiritSettings($newId, [
                'spiritmode' => 1003,
                'showspiritpoints' => 1,
                'showspiritcomments' => 1,
                'showspiritcommentstoteams' => 0,
                'showspiritpointsonlyoncomplete' => 0,
                'lockteamspiritonsubmit' => 0,
            ]);
            ClearSeasonRuntimeCache();
            $this->assertEquals(0, SeasonInfo($newId)['showspiritcommentstoteams']);
        } finally {
            if (SeasonExists($newId)) {
                DeleteSeason($newId);
            }
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    // ---- Access-guard DENY decisions ----
    //
    // The guard wrappers (RequireSeasonPublicExternal / EnforcePrivateEventAccessForView /
    // EnforceSoftMaintenanceForView) end their deny path in exit()/header(), which cannot
    // be asserted in-process without terminating PHPUnit. The security-relevant decision,
    // however, lives in the boolean predicates the guards branch on. These tests pin the
    // DENY decision for a genuinely private event, so a regression that stopped blocking
    // access would fail here even though the exit() shell is untestable in-process.

    private const PRIVATE_SEASON = 'PRIV2026';

    private function insertPrivateSeason(): void
    {
        // public_event and api_public both 0 → not published on public pages or externally.
        DBQuery(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen,
                type, istournament, isinternational, isnationalteams, organizer, category,
                showspiritpoints, use_season_points, hide_time_on_scoresheet, event_readonly,
                api_public, timezone, spiritmode)
             VALUES ('" . self::PRIVATE_SEASON . "', 'Private Event', '2026-06-01 09:00:00',
                '2026-06-02 18:00:00', 0, 0, 'outdoor', 1, 0, 0, 'Org', 'test',
                0, 0, 0, 0, 0, 'Europe/Helsinki', 1003)"
        );
        ClearSeasonRuntimeCache();
    }

    private function dropPrivateSeason(): void
    {
        DBQuery("DELETE FROM uo_season WHERE season_id='" . self::PRIVATE_SEASON . "'");
        ClearSeasonRuntimeCache();
    }

    public function testIsSeasonPublicExternalIsFalseForPrivateEvent(): void
    {
        $this->insertPrivateSeason();
        try {
            // Anchor: the row really exists and is genuinely private, otherwise a false
            // result below would be meaningless (SeasonInfo is null for a missing season).
            $info = SeasonInfo(self::PRIVATE_SEASON);
            $this->assertIsArray($info);
            $this->assertEmpty($info['public_event']);
            $this->assertEmpty($info['api_public']);

            // The deny decision behind RequireSeasonPublicExternal.
            $this->assertFalse(IsSeasonPublicExternal(self::PRIVATE_SEASON));
            // Contrast: the published fixture season is allowed.
            $this->assertTrue(IsSeasonPublicExternal('HRN2026'));
        } finally {
            $this->dropPrivateSeason();
        }
    }

    public function testIsSeasonPublicEventIsFalseForPrivateEvent(): void
    {
        $this->insertPrivateSeason();
        try {
            $this->assertFalse(IsSeasonPublicEvent(self::PRIVATE_SEASON));
            $this->assertTrue(IsSeasonPublicEvent('HRN2026'));
        } finally {
            $this->dropPrivateSeason();
        }
    }

    public function testCanAccessSeasonDeniesAnonymousUserForPrivateEvent(): void
    {
        // The deny decision behind EnforcePrivateEventAccessForView's redirect+exit.
        $this->insertPrivateSeason();
        $_SESSION = [];
        try {
            // Anchor: the row exists and is private (a false result for a missing season
            // would be a false pass — the same latent flaw this pass is meant to remove).
            $this->assertIsArray(SeasonInfo(self::PRIVATE_SEASON));

            // Privacy contrast for the SAME anonymous user: private → deny, public → allow.
            // This proves the gate discriminates on privacy, not that access is hardwired off.
            $this->assertFalse(CanAccessSeason(self::PRIVATE_SEASON));
            $this->assertTrue(CanAccessSeason('HRN2026'));
        } finally {
            $this->dropPrivateSeason();
        }
    }

    public function testCanAccessSeasonAllowsSuperAdminForPrivateEvent(): void
    {
        // A superadmin bypasses the private-event gate (allow branch of the same decision).
        $this->insertPrivateSeason();
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertTrue(CanAccessSeason(self::PRIVATE_SEASON));
        } finally {
            $this->dropPrivateSeason();
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
        // EnrollSeasons() takes no parameters; PHP silently ignores the extra args
        // passed here. Same as the no-arg call: fixture's season has enrollopen=0.
        $result = EnrollSeasons([], 'HRN2026');
        $this->assertSame([], $result);
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

    // ---- SeasonReservations group filter ----

    public function testSeasonReservationsWithGroupFilterReturnsFilteredResults(): void
    {
        // Passing a specific group triggers the $group != 'all' branch (line 683).
        $reservations = SeasonReservations('HRN2026', 'nonexistent_group');
        $this->assertIsArray($reservations);
        $this->assertCount(0, $reservations);
    }

    // ---- AddSeason duplicate guard ----

    public function testAddSeasonReturnsFalseForExistingSeasonId(): void
    {
        // SeasonExists check at the top of AddSeason returns false for duplicate.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = AddSeason('HRN2026', self::minimalSeasonParams('Duplicate'));
            $this->assertFalse($result);
        } finally {
            $_SESSION = [];
            ClearSeasonRuntimeCache();
        }
    }

    // ---- AddSeason with comment ----

    public function testAddSeasonWithCommentCallsSetComment(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7);
        try {
            $result = AddSeason($newId, self::minimalSeasonParams('Commented Season'), 'test comment');
            $this->assertTrue((bool) $result);
        } finally {
            if (SeasonExists($newId)) {
                DeleteSeason($newId);
            }
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    // ---- SetSeason with comment ----

    public function testSetSeasonWithCommentCallsSetComment(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = 'TST' . substr(uniqid(), -7);
        try {
            AddSeason($newId, self::minimalSeasonParams('Pre-comment Season'));
            $params = self::minimalSeasonParams('Updated With Comment');
            $params['public_event'] = 0;
            $result = SetSeason($newId, $params, 'update comment');
            $this->assertTrue((bool) $result);
        } finally {
            if (SeasonExists($newId)) {
                DeleteSeason($newId);
            }
            ClearSeasonRuntimeCache();
            $_SESSION = [];
        }
    }

    // ---- SetSeasonSpiritSettings without rights ----

    public function testSetSeasonSpiritSettingsReturnsFalseWithoutRights(): void
    {
        unset($_SESSION['uid'], $_SESSION['userproperties']['userrole']);
        $result = SetSeasonSpiritSettings('HRN2026', [
            'spiritmode'                   => 0,
            'showspiritpoints'             => 0,
            'showspiritcomments'           => 0,
            'showspiritpointsonlyoncomplete' => 0,
            'lockteamspiritonsubmit'       => 0,
        ]);
        $this->assertFalse($result);
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    // ---- EnrollSeasons skips private seasons ----

    public function testEnrollSeasonsSkipsPrivateSeasonWhenNotLoggedIn(): void
    {
        // Private season with enrollopen=1 is skipped because CanAccessSeason returns false.
        $tmpId = 'TST' . substr(uniqid(), -7);
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                  istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                  use_season_points, hide_time_on_scoresheet, event_readonly, api_public, timezone, spiritmode,
                  public_event)
                 VALUES ('$tmpId', 'Private Enroll', '2030-01-01', '2030-12-31', 0, 1, 'outdoor',
                  0, 0, 0, '', '', 0, 0, 0, 0, 0, 'UTC', 0, 0)");
        unset($_SESSION['uid'], $_SESSION['userproperties']['userrole']);
        ClearSeasonRuntimeCache();
        try {
            $result = EnrollSeasons();
            $this->assertIsArray($result);
            $this->assertArrayNotHasKey($tmpId, $result);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='" . DBEscapeString($tmpId) . "'");
            ClearSeasonRuntimeCache();
            $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
        }
    }
}
