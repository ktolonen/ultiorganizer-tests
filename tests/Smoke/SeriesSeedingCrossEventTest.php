<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/seriesseeding.php posts one (team, seed) pair per team of the ?series=
 * division to SetTeamSeeding(), which checks hasEditTeamsRight() on that
 * division and updates the posted team id alone. An admin of one event can so
 * rewrite the seeding of another event's teams.
 *
 * The test seeds a second event with one division, one team and an admin, and
 * posts seedings through that division's page.
 */
final class SeriesSeedingCrossEventTest extends TestCase
{
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_ADMIN = 'harness-other-admin';
    private const OTHER_TEAM = 320;

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
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testAdminCanSeedOwnDivisionsTeam(): void
    {
        // Contrast: the request does write a seed for the division's own team.
        self::seed(self::OTHER_TEAM, 7);

        $this->assertSame('7', self::rank(self::OTHER_TEAM));
    }

    public function testAdminCannotSeedAnotherEventsTeam(): void
    {
        self::seed(300, 9);

        $this->assertSame('1', self::rank(300));
    }

    private static function seed(int $teamId, int $seed): void
    {
        self::request(
            '/index.php?view=admin/seriesseeding&series=' . self::OTHER_SERIES,
            'POST',
            self::login(self::OTHER_ADMIN),
            http_build_query(['save' => '1', 'team0' => (string) $teamId, 'seed0' => (string) $seed, 'backurl' => '']),
        );
    }

    private static function rank(int $teamId): string
    {
        return (string) DBQueryToValueUncached(sprintf("SELECT rank FROM uo_team WHERE team_id=%d", $teamId));
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
        DBQuery("UPDATE uo_team SET rank=1 WHERE team_id=300");
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", self::OTHER_TEAM));
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
