<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Names entered by other users on login-gated pages.
 *
 * Club names (a team admin's club, or an enrolled team's club once
 * confirmed), a registered user's own display name, team names, the event
 * organizer and reservation groups are printed raw into markup on the pages
 * below -- club names inside an inline <script> string literal. The test
 * appends a harmless element to each, logs in as the fixture superadmin, and
 * asserts every page shows the name but never the element as markup.
 *
 * Only the injected marker is asserted, so the test is locale-independent.
 */
final class StoredNameEscapingLoggedInTest extends TestCase
{
    private const MARKER = '<x-harness-mark>';

    private static ?string $cookie = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        $m = DBEscapeString(self::MARKER);
        DBQuery("UPDATE uo_season SET organizer=CONCAT(organizer, '$m') WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_series SET name=CONCAT(name, '$m') WHERE series_id=100");
        DBQuery("UPDATE uo_team SET name=CONCAT(name, '$m') WHERE team_id IN (300, 301)");
        DBQuery("UPDATE uo_reservation SET reservationgroup=CONCAT(reservationgroup, '$m') WHERE id IN (500, 501)");
        DBQuery("UPDATE uo_users SET name=CONCAT(name, '$m') WHERE userid='admin'");
        DBQuery("INSERT INTO uo_club (club_id, name, valid) VALUES (953, 'Harness Club$m', 1)");
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET organizer='Harness Org' WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_series SET name='Open' WHERE series_id=100");
        DBQuery("UPDATE uo_team SET name='Helsinki Heat' WHERE team_id=300");
        DBQuery("UPDATE uo_team SET name='Tampere Tempest' WHERE team_id=301");
        DBQuery("UPDATE uo_reservation SET reservationgroup='Harness Invitational 2026' WHERE id IN (500, 501)");
        DBQuery("UPDATE uo_users SET name='Harness Admin' WHERE userid='admin'");
        DBQuery("DELETE FROM uo_club WHERE club_id=953");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            // Contrast: a login-gated page that escapes the same team names.
            'event teams (escaped)' => ['/index.php?view=admin/seasonteams&season=HRN2026&series=100'],
            // Club names inside a <script> string literal.
            'enroll team' => ['/index.php?view=user/enrollteam&season=HRN2026'],
            'edit team' => ['/index.php?view=admin/addseasonteams&team=300'],
            // The event organizer.
            'event admin' => ['/index.php?view=admin/seasonadmin&season=HRN2026'],
            // Team and division names in the standings editor.
            'event statistics' => ['/index.php?view=admin/stats&season=HRN2026'],
            // The logged-in user's own display name.
            'add media link' => ['/index.php?view=user/addmedialink&game=700'],
            // Reservation groups in the grouping links.
            'responsible games' => ['/index.php?view=user/respgames&season=HRN2026&series=100&group=all'],
        ];
    }

    #[DataProvider('pages')]
    public function testNamesAreEscaped(string $path): void
    {
        $body = self::get($path);

        $this->assertStringContainsString('x-harness-mark', $body, 'the page shows one of the names');
        $this->assertStringNotContainsString(self::MARKER, $body);
    }

    private static function get(string $path): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        [$status, $body] = self::request($path, 'GET', ['Cookie: ' . self::sessionCookie()]);
        self::assertStringContainsString(' 200 ', $status, $path);
        return $body;
    }

    private static function sessionCookie(): string
    {
        if (self::$cookie !== null) {
            return self::$cookie;
        }
        [$status, , $headers] = self::request(
            '/index.php?view=frontpage',
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['myusername' => 'admin', 'mypassword' => 'harness-admin']),
        );
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie: ' . $status);
        self::$cookie = implode('; ', $cookies);
        return self::$cookie;
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private static function request(string $path, string $method, array $headers, string $content = ''): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
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
        $body = file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        return [$responseHeaders[0] ?? '', (string) $body, $responseHeaders];
    }
}
