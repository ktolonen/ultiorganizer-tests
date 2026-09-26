<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Content contract for the desktop scoresheet editor (user/addscoresheet.php).
 *
 * The save and refusal logic lives in the page file itself, so it only runs
 * over HTTP. This test logs in as the fixture superadmin, posts to fixture
 * game 701 (unplayed, no roster), and restores the game afterwards.
 *
 * Everything asserted is locale-independent (form values, highlight scripts,
 * database state), because the config-overrides case renders the page in fi_FI.
 */
final class ScoresheetPageTest extends TestCase
{
    private const GAME = 701;

    private static ?string $cookie = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        self::restoreGame();
    }

    protected function tearDown(): void
    {
        self::restoreGame();
        LegacyApp::closeDatabaseConnection();
    }

    public function testRefusedSaveKeepsEveryPostedField(): void
    {
        $body = self::post([
            'save' => '1',
            'secretary' => 'Scorekeeper A',
            'halftime' => '35',
            'hto0' => '12',
            'gamecomment' => 'Note A',
            'starting' => 'V',
            'isongoing' => 'on',
            'team0' => 'H',
            'goal0' => '',
            'time0' => '99.99.99',
        ]);

        // Refused over the malformed point time.
        $this->assertStringContainsString('highlightError("time0")', $body);

        $this->assertStringContainsString("id='secretary' value='Scorekeeper A'", $body);
        $this->assertStringContainsString("id='halftime' value='35'", $body);
        $this->assertStringContainsString("id='hto0' name='hto0' value='12'", $body);
        $this->assertStringContainsString('>Note A</textarea>', $body);
        $this->assertStringContainsString("id='vstart' name='starting' type='radio' checked='checked'", $body);
        $this->assertMatchesRegularExpression("/name='isongoing'\\s*checked='checked'/", $body);
        $this->assertStringContainsString("id='time0' name='time0' maxlength='8' size='8' value='99.99.99'", $body);

        self::flushQueryCaches();
        $game = DBQueryToRow(sprintf("SELECT official, halftime, isongoing FROM uo_game WHERE game_id=%d", self::GAME));
        $this->assertNull($game['official']);
        $this->assertSame(35, (int) $game['halftime']);
        $this->assertSame(0, (int) $game['isongoing']);
        $this->assertSame(0, (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_timeout WHERE game=%d", self::GAME)));
        $this->assertSame(0, (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_gameevent WHERE game=%d", self::GAME)));
    }

    /** Put game 701 back to its fixture state. */
    private static function restoreGame(): void
    {
        $game = self::GAME;
        DBQuery("DELETE FROM uo_goal WHERE game=$game");
        DBQuery("DELETE FROM uo_timeout WHERE game=$game");
        DBQuery("DELETE FROM uo_spirit_timeout WHERE game=$game");
        DBQuery("DELETE FROM uo_gameevent WHERE game=$game");
        DBQuery(sprintf("DELETE FROM uo_comment WHERE type=%d AND id='%d'", COMMENT_TYPE_GAME, $game));
        DBQuery("DELETE FROM uo_scoresheet_history WHERE game=$game");
        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0,
            halftime=35, official=NULL WHERE game_id=$game");
        self::flushQueryCaches();
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

    private static function post(array $fields): string
    {
        $headers = [
            'Cookie: ' . self::sessionCookie(),
            'Content-Type: application/x-www-form-urlencoded',
        ];
        [$status, $body] = self::request(
            '/index.php?view=user/addscoresheet&game=' . self::GAME,
            'POST',
            $headers,
            http_build_query($fields),
        );
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
