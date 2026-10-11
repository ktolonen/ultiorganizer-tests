<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * admin/select_teams.php, the drag-and-drop team-to-pool editor.
 *
 * The page answers a missing or unknown division with a warning instead of
 * rendering an editor for nothing. For a real division it lists the teams
 * without a pool in `ul#unpooled` and the teams of every pool that has not
 * started in its own `ol#res<pool>`. The inline `hide()` script moves a team
 * removed from an `ol` into `#unpooled` and relies on exactly that markup, so
 * the test pins the structure.
 *
 * The test adds an unstarted pool with one team and a second team without a
 * pool to division 100. Pool 200 stays out of the list because game 700 has
 * started.
 */
final class SelectTeamsPageTest extends TestCase
{
    private const POOL = 9200;
    private const POOLED_TEAM = 9300;
    private const UNPOOLED_TEAM = 9301;

    private string $cookie = '';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        // Clone pool 200 so every NOT NULL column has a value; no game belongs to the clone.
        DBQuery(sprintf(
            "INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames,
                timeoutlen, halftime, winningscore, played, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
                betweenpointslen, series, type, color, forfeitscore, forfeitagainst, drawsallowed)
             SELECT %d, 'Unstarted Pool', '2', visible, continuingpool, placementpool, teams, mvgames,
                timeoutlen, halftime, winningscore, 0, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap,
                betweenpointslen, series, type, color, forfeitscore, forfeitagainst, drawsallowed
             FROM uo_pool WHERE pool_id=200",
            self::POOL,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, rank, valid, series)
             VALUES (%d, 'Pooled Panthers', %d, 3, 1, 100), (%d, 'Loose Lynx', NULL, 4, 1, 100)",
            self::POOLED_TEAM,
            self::POOL,
            self::UNPOOLED_TEAM,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (%d, %d, 1, 1)",
            self::POOLED_TEAM,
            self::POOL,
        ));
        self::flushQueryCaches();
        $this->cookie = self::login();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPooledTeamSitsInItsPoolListAndPoollessTeamInTheUnpooledList(): void
    {
        [$status, $body] = self::get('/index.php?view=admin/select_teams&series=100', $this->cookie);

        $this->assertStringContainsString(' 200 ', $status);
        $unpooled = self::listContents($body, 'ul', 'unpooled');
        $pool = self::listContents($body, 'ol', 'res' . self::POOL);
        $this->assertStringContainsString("id='team" . self::UNPOOLED_TEAM . "'", $unpooled);
        $this->assertStringNotContainsString("id='team" . self::POOLED_TEAM . "'", $unpooled);
        $this->assertStringContainsString("id='team" . self::POOLED_TEAM . "'", $pool);
        $this->assertStringNotContainsString("id='team" . self::UNPOOLED_TEAM . "'", $pool);
    }

    public function testStartedPoolIsNotEditable(): void
    {
        // Pool 200 has a started game, so its teams cannot be moved.
        [, $body] = self::get('/index.php?view=admin/select_teams&series=100', $this->cookie);

        $this->assertStringNotContainsString("id='res200'", $body);
        $this->assertStringContainsString("id='res" . self::POOL . "'", $body);
    }

    public function testRemoveLinkOfAPooledTeamHasAnOrderedListParentForHide(): void
    {
        // hide() moves a team to #unpooled only when its parent is an <ol>, and
        // removes it when the parent is the unpooled <ul>.
        [, $body] = self::get('/index.php?view=admin/select_teams&series=100', $this->cookie);

        $this->assertMatchesRegularExpression(
            '/<ol id=\'res' . self::POOL . '\'.*?<li[^>]*id=\'team' . self::POOLED_TEAM . '\'[^>]*>.*?hide\("team' . self::POOLED_TEAM . '"\)/s',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/<ul id=\'unpooled\'.*?<li[^>]*id=\'team' . self::UNPOOLED_TEAM . '\'[^>]*>.*?hide\("team' . self::UNPOOLED_TEAM . '"\)/s',
            $body,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownDivisionQueries(): array
    {
        return [
            'unknown id' => ['&series=999999'],
            'no series parameter' => [''],
            'non-numeric id' => ['&series=abc'],
        ];
    }

    #[DataProvider('unknownDivisionQueries')]
    public function testUnknownDivisionShowsAWarningInsteadOfTheEditor(string $query): void
    {
        [$status, $body] = self::get('/index.php?view=admin/select_teams' . $query, $this->cookie);

        $this->assertStringContainsString(' 200 ', $status);
        $this->assertStringContainsString("<p class='warning'>", $body);
        $this->assertStringNotContainsString("id='unpooled'", $body);
        $this->assertStringNotContainsString("id='saveButton'", $body);
    }

    /**
     * Returns the markup between the opening tag with the given id and the matching close tag.
     */
    private static function listContents(string $body, string $tag, string $id): string
    {
        $found = preg_match("/<$tag id='" . preg_quote($id, '/') . "'[^>]*>(.*?)<\\/$tag>/s", $body, $m);
        self::assertSame(1, $found, "no <$tag id='$id'> in the page");
        return $m[1];
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", self::POOL));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id IN (%d, %d)", self::POOLED_TEAM, self::UNPOOLED_TEAM));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", self::POOL));
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }

    private static function login(): string
    {
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
        return implode('; ', $cookies);
    }

    /** @return array{0: string, 1: string} */
    private static function get(string $path, string $cookie): array
    {
        self::flushQueryCaches();
        [$status, $body] = self::request($path, 'GET', ['Cookie: ' . $cookie]);
        return [$status, $body];
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
