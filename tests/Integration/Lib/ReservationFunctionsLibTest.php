<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

final class ReservationFunctionsLibTest extends TestCase
{
    // Fixture constants:
    //   location_id=400 ('Harness Field Complex', fields=2)
    //   reservation_id=500 (fieldname='1', starttime='2026-06-01 10:00:00', season='HRN2026')
    //   reservation_id=501 (fieldname='2', starttime='2026-06-01 14:00:00', season='HRN2026')
    //   game_id=700 → reservation=500 (hometeam=300, visitorteam=301), in pool 200 (timetable=1)
    //   game_id=701 → reservation=501

    /** @var int[] reservation IDs created during a test, deleted in tearDown */
    private array $createdReservationIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'reservation.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        // Prevent getSessionLocale() from calling GetDefaultLocale() (in configuration.functions.php, not loaded).
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdReservationIds as $resId) {
            DBQuery(sprintf("DELETE FROM uo_reservation WHERE id=%d", (int) $resId));
        }
        $this->createdReservationIds = [];
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    private function insertReservation(
        int    $location,
        string $fieldname,
        string $group,
        string $starttime,
        string $endtime,
        string $season
    ): int {
        $date = substr($starttime, 0, 10);
        DBQuery(sprintf(
            "INSERT INTO uo_reservation (location, fieldname, reservationgroup, date, starttime, endtime, season)
             VALUES (%d, '%s', '%s', '%s', '%s', '%s', '%s')",
            $location,
            DBEscapeString($fieldname),
            DBEscapeString($group),
            DBEscapeString($date),
            DBEscapeString($starttime),
            DBEscapeString($endtime),
            DBEscapeString($season)
        ));
        $id = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdReservationIds[] = $id;
        return $id;
    }

    // --- ReservationLocationValid ---

    public function testReservationLocationValidReturnsTrueForZero(): void
    {
        $this->assertTrue(ReservationLocationValid(0));
    }

    public function testReservationLocationValidReturnsTrueForNegative(): void
    {
        $this->assertTrue(ReservationLocationValid(-5));
    }

    public function testReservationLocationValidReturnsTrueForKnownLocation(): void
    {
        $this->assertTrue(ReservationLocationValid(400));
    }

    public function testReservationLocationValidReturnsFalseForUnknownLocation(): void
    {
        $this->assertFalse(ReservationLocationValid(99999));
    }

    // --- ReservationPlaceText ---

    public function testReservationPlaceTextBothNonEmpty(): void
    {
        $result = ReservationPlaceText('Harness Field Complex', '1');
        $this->assertStringContainsString('Harness Field Complex', $result);
        $this->assertStringContainsString('1', $result);
    }

    public function testReservationPlaceTextOnlyLocationName(): void
    {
        $this->assertSame('Only Location', ReservationPlaceText('Only Location', ''));
    }

    public function testReservationPlaceTextOnlyFieldName(): void
    {
        $result = ReservationPlaceText('', 'B');
        $this->assertStringContainsString('B', $result);
    }

    public function testReservationPlaceTextBothEmpty(): void
    {
        $this->assertSame('', ReservationPlaceText('', ''));
    }

    // --- ReservationInfo ---

    public function testReservationInfoReturnsRowForFixtureReservation(): void
    {
        $info = ReservationInfo(500);
        $this->assertIsArray($info);
        $this->assertSame('500', (string) $info['id']);
        $this->assertSame('HRN2026', $info['season']);
        $this->assertSame('1', $info['fieldname']);
    }

    public function testReservationInfoReturnsNullIdForUnknownId(): void
    {
        // COUNT(*) makes this an aggregate — always returns a row; id column will be NULL.
        $info = ReservationInfo(99999);
        $this->assertNull($info['id']);
    }

    // --- ReservationName ---

    public function testReservationNameReturnsNonEmptyString(): void
    {
        $info = ReservationInfo(500);
        $name = ReservationName($info);
        $this->assertIsString($name);
        $this->assertNotSame('', $name);
    }

    // --- ReservationGames ---

    public function testReservationGamesReturnsFixtureGame(): void
    {
        $games = ReservationGames(500);
        $this->assertIsArray($games);
        $ids = array_map('strval', array_column($games, 'game_id'));
        $this->assertContains('700', $ids);
    }

    public function testReservationGamesFiltersBySeasonId(): void
    {
        $games = ReservationGames(500, 'HRN2026');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
    }

    public function testReservationGamesReturnsEmptyForUnknownReservation(): void
    {
        $games = ReservationGames(99999);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    // --- ReservationGetGame ---

    public function testReservationGetGameReturnsGameForFixtureReservation(): void
    {
        $games = ReservationGetGame(500);
        $this->assertIsArray($games);
        $ids = array_map('strval', array_column($games, 'game_id'));
        $this->assertContains('700', $ids);
    }

    public function testReservationGetGameWithTimeFilterFindsGame(): void
    {
        // Game 700 time matches reservation 500 starttime.
        $games = ReservationGetGame(500, '2026-06-01 10:00:00');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
    }

    public function testReservationGetGameReturnsEmptyForUnknownId(): void
    {
        $games = ReservationGetGame(99999);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    // --- ReservationGamesByField ---

    public function testReservationGamesByFieldReturnsResult(): void
    {
        $result = ReservationGamesByField('1');
        $this->assertNotFalse($result);
    }

    public function testReservationGamesByFieldWithSeasonFilterReturnsResult(): void
    {
        $result = ReservationGamesByField('1', 'HRN2026');
        $this->assertNotFalse($result);
    }

    // --- ReservationFields ---

    public function testReservationFieldsReturnsResultForKnownSeason(): void
    {
        $result = ReservationFields('HRN2026');
        $this->assertNotFalse($result);
    }

    // --- ResponsibleReservationGames ---

    public function testResponsibleReservationGamesReturnsEmptyForEmptyList(): void
    {
        $games = ResponsibleReservationGames(500, []);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    public function testResponsibleReservationGamesReturnsMatchingGame(): void
    {
        $games = ResponsibleReservationGames(500, [700]);
        $this->assertIsArray($games);
        $ids = array_map('strval', array_column($games, 'game_id'));
        $this->assertContains('700', $ids);
    }

    public function testResponsibleReservationGamesWithNullPlaceReturnsEmpty(): void
    {
        // placeId=0 → WHERE res.id IS NULL; game 700 has reservation=500, so not returned.
        $games = ResponsibleReservationGames(0, [700]);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    // --- ReservationSeasons ---

    public function testReservationSeasonsReturnsSeasonForFixtureReservation(): void
    {
        $seasons = ReservationSeasons(500);
        $this->assertIsArray($seasons);
        $this->assertContains('HRN2026', $seasons);
    }

    public function testReservationSeasonsReturnsEmptyForUnknownId(): void
    {
        $seasons = ReservationSeasons(99999);
        $this->assertIsArray($seasons);
        $this->assertCount(0, $seasons);
    }

    // --- SetReservation ---

    public function testSetReservationUpdatesFieldname(): void
    {
        $id = $this->insertReservation(400, 'X', 'Set Test Group', '2026-07-01 10:00:00', '2026-07-01 12:00:00', 'HRN2026');

        SetReservation($id, [
            'location'         => 400,
            'fieldname'        => 'Y',
            'reservationgroup' => 'Set Test Group Updated',
            'date'             => '2026-07-02',
            'starttime'        => '2026-07-02 11:00:00',
            'endtime'          => '2026-07-02 13:00:00',
            'season'           => 'HRN2026',
        ]);

        $info = ReservationInfo($id);
        $this->assertSame('Y', $info['fieldname']);
    }

    public function testSetReservationWithZeroLocationSetsNull(): void
    {
        $id = $this->insertReservation(400, 'Z', 'NullLoc Group', '2026-07-01 14:00:00', '2026-07-01 16:00:00', 'HRN2026');

        SetReservation($id, [
            'location'         => 0,
            'fieldname'        => 'Z',
            'reservationgroup' => 'NullLoc Group',
            'date'             => '2026-07-01',
            'starttime'        => '2026-07-01 14:00:00',
            'endtime'          => '2026-07-01 16:00:00',
            'season'           => 'HRN2026',
        ]);

        $info = ReservationInfo($id);
        $this->assertNull($info['location']);
    }

    // --- AddReservation and RemoveReservation ---

    public function testAddReservationCreatesRowAndRemoveDeletesIt(): void
    {
        $data = [
            'location'         => 400,
            'fieldname'        => 'New',
            'reservationgroup' => 'Add Test Group',
            'date'             => '2026-08-01',
            'starttime'        => '2026-08-01 09:00:00',
            'endtime'          => '2026-08-01 11:00:00',
            'season'           => 'HRN2026',
        ];

        $id = (int) AddReservation($data);
        $this->assertGreaterThan(0, $id);
        $this->createdReservationIds[] = $id;

        $info = ReservationInfo($id);
        $this->assertSame('New', $info['fieldname']);

        RemoveReservation($id, 'HRN2026');
        // ReservationInfo uses COUNT aggregate so always returns a row; id will be NULL after delete.
        $infoAfterDelete = ReservationInfo($id);
        $this->assertNull($infoAfterDelete['id']);

        $this->createdReservationIds = array_diff($this->createdReservationIds, [$id]);
    }

    public function testAddReservationWithZeroLocationSetsNull(): void
    {
        $data = [
            'location'         => 0,
            'fieldname'        => 'NullLocAdd',
            'reservationgroup' => 'Null Loc Add Group',
            'date'             => '2026-08-02',
            'starttime'        => '2026-08-02 09:00:00',
            'endtime'          => '2026-08-02 11:00:00',
            'season'           => 'HRN2026',
        ];

        $id = (int) AddReservation($data);
        $this->assertGreaterThan(0, $id);
        $this->createdReservationIds[] = $id;
    }

    // --- ReservationInfoArray ---

    public function testReservationInfoArrayReturnsNestedStructureKeyedByGameday(): void
    {
        $result = ReservationInfoArray([500]);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        // Keys should be YYYYMMDD strings.
        foreach (array_keys($result) as $key) {
            $this->assertMatchesRegularExpression('/^\d{8}$/', (string) $key);
        }
    }

    // --- ReservationGroupTimeoutStats ---

    public function testReservationGroupTimeoutStatsReturnsArray(): void
    {
        $stats = ReservationGroupTimeoutStats();
        $this->assertIsArray($stats);
    }

    public function testReservationGroupTimeoutStatsHasExpectedShape(): void
    {
        $stats = ReservationGroupTimeoutStats();
        $this->assertNotEmpty($stats);
        $row = $stats[0];
        $this->assertArrayHasKey('reservationgroup', $row);
        $this->assertArrayHasKey('games', $row);
        $this->assertArrayHasKey('timeouts', $row);
    }

    // --- UnscheduledTeams ---

    public function testUnscheduledTeamsAsSuperadminReturnsArray(): void
    {
        ob_start();
        $teams = UnscheduledTeams();
        ob_end_clean();

        $this->assertIsArray($teams);
    }

    public function testUnscheduledTeamsWithNoAdminRolesReturnsEmpty(): void
    {
        // Non-superadmin with no season/series admin roles → criteria is empty → returns [].
        $_SESSION['userproperties']['userrole']['superadmin'] = false;
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        unset($_SESSION['userproperties']['userrole']['seriesadmin']);

        $result = UnscheduledTeams();

        $_SESSION['userproperties']['userrole']['superadmin'] = true;

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- CanDeleteReservation ---

    public function testCanDeleteReservationReturnsFalseWhenGamesAttached(): void
    {
        $this->assertFalse(CanDeleteReservation(500));
    }

    public function testCanDeleteReservationReturnsTrueWhenNoGamesAttached(): void
    {
        $id = $this->insertReservation(400, 'Free', 'Free Group', '2026-09-01 08:00:00', '2026-09-01 10:00:00', 'HRN2026');
        $this->assertTrue(CanDeleteReservation($id));
    }
}
