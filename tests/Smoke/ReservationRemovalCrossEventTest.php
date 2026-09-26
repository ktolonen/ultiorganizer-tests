<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/reservations.php checks access for the event named in the URL
 * (?season=) and passes that event, with the posted reservation id, to
 * RemoveReservation(). The function checks the caller's rights on the event it
 * is given and deletes the reservation by id alone, so an admin of one event
 * can delete another event's field reservations. (SpiritDeleteSotgToken(),
 * which has the same signature shape, scopes its write to the given event.)
 *
 * The test seeds a second event with an admin, one reservation in each event,
 * and posts deletions through the second event's page.
 */
final class ReservationRemovalCrossEventTest extends TestCase
{
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_ADMIN = 'harness-other-admin';
    private const FIXTURE_RESERVATION = 590;
    private const OTHER_RESERVATION = 591;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', 'Other Cup', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0, 1, 'Europe/Helsinki', 1003)",
            self::OTHER_SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_series (series_id, name, ordering, season, valid, type, color, pool_template)
             VALUES (%d, 'Other Open', 'A', '%s', 1, 'open', '336699', NULL)",
            self::OTHER_SERIES,
            self::OTHER_SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_users (userid, password, name, email)
             SELECT '%s', password, 'Other Admin', 'other@example.com' FROM uo_users WHERE userid='admin'",
            self::OTHER_ADMIN,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_userproperties (userid, name, value) VALUES ('%s', 'userrole', 'seasonadmin:%s')",
            self::OTHER_ADMIN,
            self::OTHER_SEASON,
        ));
        foreach ([self::FIXTURE_RESERVATION => 'HRN2026', self::OTHER_RESERVATION => self::OTHER_SEASON] as $id => $season) {
            DBQuery(sprintf(
                "INSERT INTO uo_reservation (id, location, fieldname, reservationgroup, starttime, endtime, season, date)
                 VALUES (%d, 400, '9', 'Spare', '2026-06-02 10:00:00', '2026-06-02 11:00:00', '%s', '2026-06-02 00:00:00')",
                $id,
                $season,
            ));
        }
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testAdminCanRemoveOwnEventsReservation(): void
    {
        // Contrast: the same request against the admin's own event deletes the row.
        self::removeReservation(self::OTHER_RESERVATION);

        $this->assertFalse(self::reservationExists(self::OTHER_RESERVATION));
    }

    public function testAdminCannotRemoveAnotherEventsReservation(): void
    {
        self::removeReservation(self::FIXTURE_RESERVATION);

        $this->assertTrue(self::reservationExists(self::FIXTURE_RESERVATION));
    }

    private static function removeReservation(int $reservationId): void
    {
        self::request(
            '/index.php?view=admin/reservations&season=' . self::OTHER_SEASON,
            'POST',
            self::login(),
            http_build_query(['remove_x' => '1', 'hiddenDeleteId' => (string) $reservationId]),
        );
    }

    private static function reservationExists(int $reservationId): bool
    {
        return DBQueryToValueUncached(sprintf("SELECT id FROM uo_reservation WHERE id=%d", $reservationId)) !== null;
    }

    private static function login(): string
    {
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query(['myusername' => self::OTHER_ADMIN, 'mypassword' => 'harness-admin']),
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 20,
            ],
        ]);
        file_get_contents($baseUrl . '/index.php?view=frontpage', false, $context);
        $cookies = [];
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie');
        $cookie = implode('; ', $cookies);

        // Precondition: the account can use its own event's reservations page.
        $own = self::request('/index.php?view=admin/reservations&season=' . self::OTHER_SEASON, 'GET', $cookie);
        self::assertStringContainsString('Spare', $own);
        return $cookie;
    }

    private static function request(string $path, string $method, string $cookie, string $content = ''): string
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $headers = ['Cookie: ' . $cookie];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 20,
            ],
        ]);
        return (string) file_get_contents($baseUrl . $path, false, $context);
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf(
            "DELETE FROM uo_reservation WHERE id IN (%d, %d)",
            self::FIXTURE_RESERVATION,
            self::OTHER_RESERVATION,
        ));
        DBQuery(sprintf("DELETE FROM uo_userproperties WHERE userid='%s'", self::OTHER_ADMIN));
        DBQuery(sprintf("DELETE FROM uo_users WHERE userid='%s'", self::OTHER_ADMIN));
        DBQuery(sprintf("DELETE FROM uo_series WHERE series_id=%d", self::OTHER_SERIES));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::OTHER_SEASON));
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
