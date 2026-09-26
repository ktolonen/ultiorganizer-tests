<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/saveschedule.php (the drag-and-drop scheduler's save endpoint) passes
 * each posted "reservation:game/minute" pair to ScheduleGame(), which checks
 * hasEditGamesRight() on the game's division and stores the posted reservation
 * id without checking it belongs to the game's event. An event admin can so
 * put their games into another event's field reservations, where they show on
 * that event's field schedule and fall under its reservation game admins.
 *
 * The test seeds a second event with one game, one reservation and an admin,
 * and posts schedules for that game.
 */
final class ScheduleReservationCrossEventTest extends TestCase
{
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_ADMIN = 'harness-other-admin';
    private const OTHER_TEAM = 320;
    private const OTHER_TEAM_2 = 321;
    private const OTHER_POOL = 220;
    private const OTHER_GAME = 720;
    private const OTHER_RESERVATION = 592;

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
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, club, rank, activerank, valid, series, country, abbreviation)
             VALUES (%d, 'Other Team', NULL, NULL, 1, 1, 1, %d, NULL, 'OTH')",
            self::OTHER_TEAM,
            self::OTHER_SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, club, rank, activerank, valid, series, country, abbreviation)
             VALUES (%d, 'Other Team Two', NULL, NULL, 2, 2, 1, %d, NULL, 'OT2')",
            self::OTHER_TEAM_2,
            self::OTHER_SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames,
                timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore, timeouts,
                timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, series, type, timeslot, color,
                forfeitscore, forfeitagainst, follower, drawsallowed, playoff_template)
             VALUES (%d, 'Other Pool', '1', 1, 0, 0, 2, 0, 70, 35, 15, NULL, NULL, 0, NULL, NULL, 2, 'half',
                1, 'soft', 90, %d, 1, 60, '336699', 15, 0, NULL, 0, NULL)",
            self::OTHER_POOL,
            self::OTHER_SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_game (game_id, hometeam, visitorteam, reservation, time, valid, isongoing, hasstarted)
             VALUES (%d, %d, %d, NULL, NULL, 1, 0, 0)",
            self::OTHER_GAME,
            self::OTHER_TEAM,
            self::OTHER_TEAM_2,
        ));
        DBQuery(sprintf("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (%d, %d, 1)", self::OTHER_GAME, self::OTHER_POOL));
        DBQuery(sprintf(
            "INSERT INTO uo_reservation (id, location, fieldname, reservationgroup, starttime, endtime, season, date)
             VALUES (%d, 400, '9', 'Other day', '2025-06-01 10:00:00', '2025-06-01 18:00:00', '%s', '2025-06-01 00:00:00')",
            self::OTHER_RESERVATION,
            self::OTHER_SEASON,
        ));
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testAdminCanScheduleIntoOwnEventsReservation(): void
    {
        // Contrast: the endpoint stores a reservation of the game's own event.
        self::schedule(self::OTHER_RESERVATION);

        $this->assertSame((string) self::OTHER_RESERVATION, self::gameReservation());
    }

    public function testAdminCannotScheduleIntoAnotherEventsReservation(): void
    {
        self::schedule(501);

        $this->assertSame('', self::gameReservation());
    }

    private static function schedule(int $reservationId): void
    {
        self::request(
            '/index.php?view=admin/saveschedule',
            'POST',
            self::login(self::OTHER_ADMIN),
            $reservationId . ':' . self::OTHER_GAME . '/0',
        );
    }

    private static function gameReservation(): string
    {
        return (string) DBQueryToValueUncached(sprintf(
            "SELECT reservation FROM uo_game WHERE game_id=%d",
            self::OTHER_GAME,
        ));
    }

    private static function login(string $user): string
    {
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query(['myusername' => $user, 'mypassword' => 'harness-admin']),
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

        // Precondition: the session is logged in (a login-only page renders).
        $own = self::request('/index.php?view=user/userinfo', 'GET', $cookie);
        self::assertStringContainsString($user === 'admin' ? 'Harness Admin' : 'Other Admin', $own);
        return $cookie;
    }

    private static function request(string $path, string $method, string $cookie, string $content = ''): string
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $headers = ['Cookie: ' . $cookie];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: text/plain';
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
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", self::OTHER_GAME));
        DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", self::OTHER_GAME));
        DBQuery(sprintf("DELETE FROM uo_reservation WHERE id=%d", self::OTHER_RESERVATION));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", self::OTHER_POOL));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id IN (%d, %d)", self::OTHER_TEAM, self::OTHER_TEAM_2));
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
