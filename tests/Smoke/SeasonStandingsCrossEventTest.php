<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/seasonstandings.php checks access for the event named in the URL
 * (?season=) and then recalculates whatever PoolId the form posts, through the
 * unchecked ResolvePoolStandings(). An admin of one event can so rewrite the
 * standings of a pool in another event.
 *
 * The test seeds a second event with one division and an admin for it,
 * scrambles fixture pool 200's activerank, and posts a recalculation of pool
 * 200 through the second event's page.
 */
final class SeasonStandingsCrossEventTest extends TestCase
{
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_ADMIN = 'harness-other-admin';

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
        DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=300");
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=301");
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_team_pool SET activerank=1 WHERE pool=200 AND team=300");
        DBQuery("UPDATE uo_team_pool SET activerank=2 WHERE pool=200 AND team=301");
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testFixtureEventAdminPageRefusesTheOtherEventsAdmin(): void
    {
        // Precondition: the account has no access to the fixture event's page.
        $body = self::request('/index.php?view=admin/seasonstandings&season=HRN2026', 'GET', self::login());

        $this->assertStringNotContainsString("name='recalculate'", $body);
    }

    public function testOtherEventsPageCannotRecalculateAFixturePool(): void
    {
        $cookie = self::login();

        self::request(
            '/index.php?view=admin/seasonstandings&season=' . self::OTHER_SEASON,
            'POST',
            $cookie,
            http_build_query(['recalculate' => '1', 'PoolId' => '200']),
        );

        $this->assertSame('2', self::activerank(300), 'the fixture pool was recalculated');
    }

    private static function activerank(int $teamId): string
    {
        return (string) DBQueryToValueUncached(sprintf(
            "SELECT activerank FROM uo_team_pool WHERE pool=200 AND team=%d",
            $teamId,
        ));
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

        // Precondition: the account can use its own event's standings page.
        $own = self::request('/index.php?view=admin/seasonstandings&season=' . self::OTHER_SEASON, 'GET', $cookie);
        self::assertStringContainsString('Other Open', $own);
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
