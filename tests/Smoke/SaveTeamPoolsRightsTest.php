<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/saveteampools.php, the endpoint behind the drag-and-drop pool editor.
 *
 * The page needs only a login (admin/auth.php). Its team moves go through
 * PoolDeleteTeam()/PoolAddTeam(), which check hasEditTeamsRight(), but it then
 * calls ResolvePoolStandings() for every pool id in the request body with no
 * check at all. A body naming a pool and no teams skips the checked calls, so
 * any logged-in account can rewrite a pool's standings (and, for pools with
 * mvgames=1, trigger the automatic moves the resolver makes).
 *
 * The test scrambles pool 200's activerank so the resolver visibly rewrites
 * it, then posts the body "200" as a role-less account and as the superadmin.
 */
final class SaveTeamPoolsRightsTest extends TestCase
{
    private const PLAIN_USER = 'harness-plain';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        // Same password as the fixture superadmin, no roles.
        DBQuery(sprintf(
            "INSERT INTO uo_users (userid, password, name, email)
             SELECT '%s', password, 'Harness Plain', 'plain@example.com' FROM uo_users WHERE userid='admin'",
            self::PLAIN_USER,
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

    public function testSuperadminPostResolvesThePool(): void
    {
        // Contrast: the request does reach the resolver, which ranks game 700's winner first.
        self::post(self::login('admin'), '200');

        $this->assertSame('1', self::activerank(300));
    }

    public function testAccountWithoutRightsCannotResolveThePool(): void
    {
        $cookie = self::login(self::PLAIN_USER);

        self::post($cookie, '200');

        $this->assertSame('2', self::activerank(300), 'the scrambled standing was rewritten');
    }

    private static function activerank(int $teamId): string
    {
        self::flushQueryCaches();
        return (string) DBQueryToValueUncached(sprintf(
            "SELECT activerank FROM uo_team_pool WHERE pool=200 AND team=%d",
            $teamId,
        ));
    }

    private static function login(string $user): string
    {
        [$status, , $headers] = self::request(
            '/index.php?view=frontpage',
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['myusername' => $user, 'mypassword' => 'harness-admin']),
        );
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie: ' . $status);
        $cookie = implode('; ', $cookies);

        // Precondition: the session is logged in (a login-only page renders).
        [$status, $body] = self::request('/index.php?view=user/userinfo', 'GET', ['Cookie: ' . $cookie]);
        self::assertStringContainsString(' 200 ', $status);
        self::assertStringContainsString($user === 'admin' ? 'Harness Admin' : 'Harness Plain', $body);
        return $cookie;
    }

    private static function post(string $cookie, string $body): void
    {
        [$status] = self::request(
            '/index.php?view=admin/saveteampools',
            'POST',
            ['Cookie: ' . $cookie, 'Content-Type: text/plain'],
            $body,
        );
        self::assertStringContainsString(' 200 ', $status);
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_userproperties WHERE userid='%s'", self::PLAIN_USER));
        DBQuery(sprintf("DELETE FROM uo_users WHERE userid='%s'", self::PLAIN_USER));
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
