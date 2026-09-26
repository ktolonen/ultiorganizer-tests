<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/seasonmoves.php has no access check of its own and passes the ?season=
 * event, with each posted scheduling name id, to PoolSetSchedulingName(). That
 * function checks isSeasonAdmin() on the event it is given and renames the
 * scheduling name by id alone, so an admin of one event can rename the
 * placeholder names ("Round 1", "A1", ...) of another event's games.
 *
 * The test seeds a second event with an admin and posts a rename of fixture
 * scheduling name 600, which names fixture game 700, through the second
 * event's page.
 */
final class SchedulingNameCrossEventTest extends TestCase
{
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_ADMIN = 'harness-other-admin';
    private const FIXTURE_NAME_ID = 600;

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
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testSuperadminCanRenameTheFixtureName(): void
    {
        // Contrast: the same form post through the fixture event's own page
        // renames the row.
        self::rename(self::login('admin'), 'HRN2026');

        $this->assertSame('Renamed', self::schedulingName());
    }

    public function testOtherEventsAdminCannotRenameTheFixtureName(): void
    {
        self::rename(self::login(self::OTHER_ADMIN), self::OTHER_SEASON);

        $this->assertSame('Round 1', self::schedulingName());
    }

    private static function rename(string $cookie, string $season): void
    {
        self::request(
            '/index.php?view=admin/seasonmoves&season=' . $season,
            'POST',
            $cookie,
            http_build_query([
                'save' => '1',
                'schedulingnameEdited' => ['yes'],
                'schedulingnameId' => [(string) self::FIXTURE_NAME_ID],
                'sn0' => 'Renamed',
                'moveEdited' => ['no'],
            ]),
        );
    }

    private static function schedulingName(): string
    {
        return (string) DBQueryToValueUncached(sprintf(
            "SELECT name FROM uo_scheduling_name WHERE scheduling_id=%d",
            self::FIXTURE_NAME_ID,
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
            "UPDATE uo_scheduling_name SET name='Round 1' WHERE scheduling_id=%d",
            self::FIXTURE_NAME_ID,
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
