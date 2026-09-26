<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Content contract for the two scoresheet history pages.
 *
 * ScoresheethistoryFunctionsLibTest covers the lib layer; the logic pinned here
 * lives in the page files themselves (user/scoresheethistory.php and
 * admin/seasonscoresheethistory.php), which only run over HTTP. The fixture
 * carries no history rows and the pages need a login, so the authenticated
 * crawl never reaches them with anything to render. This test seeds rows for
 * fixture game 700 directly, logs in as the fixture superadmin over HTTP, and
 * removes its rows again afterwards.
 *
 * Everything asserted is locale-independent (row counts, CSS classes, links,
 * numbers), because the config-overrides case renders the pages in fi_FI.
 */
final class ScoresheetHistoryPageTest extends TestCase
{
    private const GAME = 700;

    private static ?string $cookie = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
        self::flushQueryCaches();
    }

    protected function tearDown(): void
    {
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testUnchangedResavesAreHiddenUntilShowAllIsRequested(): void
    {
        // Oldest first. Each "save" is a goal clear row plus its add rows, the
        // shape a desktop save writes.
        $saves = [
            ['removed' => 2, 'adds' => ['p1', 'p2']], // first block in the window: always shown
            ['removed' => 2, 'adds' => ['p1', 'p2']], // unchanged repeat: hidden
            ['removed' => 2, 'adds' => ['p1', 'p3']], // changed point: shown
            'restore',                                // resets the comparison
            ['removed' => 2, 'adds' => ['p1', 'p3']], // same as before the restore, but shown
            ['removed' => 2, 'adds' => ['p1', 'p3']], // unchanged repeat: hidden
            ['removed' => 3, 'adds' => ['p1', 'p3']], // same adds, but the clear removed a different count: shown
        ];
        $total = self::seedSaves($saves);
        $this->assertSame(19, $total);

        $body = self::fetch('?view=user/scoresheethistory&game=' . self::GAME);

        // Two repeat blocks of three rows each are hidden.
        $this->assertMatchesRegularExpression('/<p>[^<]*:\s*6\s*<a href=\'[^\']*&amp;all=1\'>/', $body);
        $this->assertSame(13, self::historyRowCount($body));

        // Contrast: the same rows with all=1 are all listed and nothing is reported hidden.
        $all = self::fetch('?view=user/scoresheethistory&game=' . self::GAME . '&all=1');
        $this->assertSame(19, self::historyRowCount($all));
        $this->assertStringNotContainsString("&amp;all=1'>", $all);
    }

    public function testAHistoryWithoutRepeatsHidesNothing(): void
    {
        self::seedSaves([
            ['removed' => 0, 'adds' => ['p1']],
            ['removed' => 1, 'adds' => ['p1', 'p2']],
        ]);

        $body = self::fetch('?view=user/scoresheethistory&game=' . self::GAME);

        $this->assertSame(5, self::historyRowCount($body));
        $this->assertStringNotContainsString("&amp;all=1'>", $body);
    }

    public function testSavedPointsNumberedFromZeroPairWithTheSameCurrentPointsByOrder(): void
    {
        // Scorekeeper used to store points from num 0 while the desktop sheet
        // stores them from 1. A saved state that differs from the current one
        // only by that offset must compare as identical.
        $snapshot = ScoresheetHistoryBuildSnapshot(self::GAME);
        $this->assertCount(4, $snapshot['goals']);
        $this->assertEquals(1, $snapshot['goals'][0]['num']);

        foreach ($snapshot['goals'] as $i => $goal) {
            $snapshot['goals'][$i]['num'] = (int) $goal['num'] - 1;
        }
        $shiftedId = self::insertSnapshot($snapshot, '2030-01-01 10:00:00');

        $body = self::fetch('?view=user/scoresheethistory&game=' . self::GAME . '&entry=' . $shiftedId);
        $this->assertStringContainsString('<h3>', $body, 'expected the saved state to render');
        $this->assertSame(0, substr_count($body, 'state-diff'));

        // Contrast: one real change on the same shifted snapshot is still marked,
        // on exactly the one point it touches.
        $snapshot['goals'][2]['time'] = (int) $snapshot['goals'][2]['time'] + 30;
        $changedId = self::insertSnapshot($snapshot, '2030-01-01 10:00:01');

        $changed = self::fetch('?view=user/scoresheethistory&game=' . self::GAME . '&entry=' . $changedId);
        $this->assertSame(1, substr_count($changed, 'state-diff'));
    }

    public function testSeasonHistoryLinksTheTeamsToTheGameplayPage(): void
    {
        self::seedSaves([['removed' => 0, 'adds' => ['p1']]]);

        $body = self::fetch('?view=admin/seasonscoresheethistory&season=HRN2026');

        // The game ID opens the per-game history, the team names open the game.
        $this->assertStringContainsString(
            "<a href='?view=user/scoresheethistory&amp;game=700'>700</a>",
            $body,
        );
        $this->assertMatchesRegularExpression(
            "/<td><a href='\\?view=gameplay&amp;game=700'>[^<]+ - [^<]+<\\/a><\\/td>/",
            $body,
        );
    }

    /**
     * Insert the saves oldest first, one second apart so the page's
     * time DESC ordering is deterministic. Returns the number of rows written.
     */
    private static function seedSaves(array $saves): int
    {
        $second = 0;
        $rows = 0;
        foreach ($saves as $save) {
            if ($save === 'restore') {
                self::insertRow('restore', 'restore', ['history_id' => 1], $second++);
                $rows++;
                continue;
            }
            self::insertRow('goal', 'clear', ['removed' => $save['removed']], $second++);
            $rows++;
            foreach ($save['adds'] as $point) {
                self::insertRow('goal', 'add', ['point' => $point], $second++);
                $rows++;
            }
        }
        self::flushQueryCaches();
        return $rows;
    }

    private static function insertRow(string $target, string $action, array $detail, int $second): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_scoresheet_history (game, time, user_id, source, target, action, detail, has_snapshot)
                VALUES (%d, '%s', 'admin', 'user', '%s', '%s', '%s', 0)",
            self::GAME,
            date('Y-m-d H:i:s', strtotime('2030-01-01 09:00:00') + $second),
            $target,
            $action,
            DBEscapeString(json_encode($detail)),
        ));
    }

    private static function insertSnapshot(array $snapshot, string $time): int
    {
        DBQuery(sprintf(
            "INSERT INTO uo_scoresheet_history (game, time, user_id, source, target, action, detail, has_snapshot, snapshot)
                VALUES (%d, '%s', 'admin', 'user', 'snapshot', 'save', '{}', 1, '%s')",
            self::GAME,
            $time,
            DBEscapeString(json_encode($snapshot)),
        ));
        $id = (int) DBQueryToValue(sprintf(
            "SELECT MAX(history_id) FROM uo_scoresheet_history WHERE game=%d AND time='%s'",
            self::GAME,
            $time,
        ));
        self::flushQueryCaches();
        return $id;
    }

    /** Rows of the history list table: its only rows carrying exactly this class. */
    private static function historyRowCount(string $body): int
    {
        return substr_count($body, "<tr class='admintablerow'>");
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }

    private static function fetch(string $query): string
    {
        $headers = ['Cookie: ' . self::sessionCookie()];
        [$status, $body] = self::request('/index.php' . $query, 'GET', $headers);
        self::assertStringContainsString(' 200 ', $status, 'unexpected status: ' . $status);
        self::assertStringNotContainsString('Insufficient rights', $body);
        self::assertDoesNotMatchRegularExpression(
            '/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:/',
            $body,
        );
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
        self::assertIsString($body, 'request failed: ' . $path);
        return [$responseHeaders[0] ?? '', $body, $responseHeaders];
    }
}
