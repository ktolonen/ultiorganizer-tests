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
 * the history_token field, database state), because the config-overrides case
 * renders the page in fi_FI.
 */
final class ScoresheetPageTest extends TestCase
{
    private const GAME = 701;

    private static ?string $cookie = null;

    private ?string $historySetting = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['uid'] = 'testuser';
        self::flushQueryCaches();
        $value = DBQueryToValue("SELECT value FROM uo_setting WHERE name='DisableScoresheetHistory'");
        $this->historySetting = $value === null ? null : (string) $value;
        self::setHistoryDisabled(false);
        self::restoreGame();
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        if ($this->historySetting !== null) {
            DBQuery(sprintf(
                "INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', '%s')",
                DBEscapeString($this->historySetting),
            ));
        }
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

    public function testSaveWithACurrentTokenSucceeds(): void
    {
        $token = self::fetchToken();

        self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) $token]);

        $this->assertSame('Scorekeeper A', self::official());
    }

    public function testSaveWithAStaleTokenIsRefusedAndKeepsEntries(): void
    {
        $token = self::fetchToken();
        $this->assertGreaterThan(0, (int) ScoresheetHistoryRecord(self::GAME, 'goal', 'add', ['num' => 1]));

        $body = self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) $token]);

        $this->assertNull(self::official());
        $this->assertStringContainsString("id='secretary' value='Scorekeeper A'", $body);
        $this->assertStringNotContainsString('highlightError("', $body);
        $this->assertGreaterThan($token, self::tokenIn($body));
    }

    public function testRetryWithTheReturnedTokenOverwrites(): void
    {
        $token = self::fetchToken();
        $this->assertGreaterThan(0, (int) ScoresheetHistoryRecord(self::GAME, 'goal', 'add', ['num' => 1]));
        $refused = self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) $token]);
        $this->assertNull(self::official());

        self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) self::tokenIn($refused)]);

        $this->assertSame('Scorekeeper A', self::official());
    }

    public function testMissingTokenIsAConflict(): void
    {
        $body = self::post(['save' => '1', 'secretary' => 'Scorekeeper A']);

        $this->assertNull(self::official());
        $this->assertStringContainsString("id='secretary' value='Scorekeeper A'", $body);
    }

    public function testMissingTokenStaysAConflictAfterAPointError(): void
    {
        // Another operator's change, made after the tokenless form was rendered.
        $this->assertGreaterThan(0, (int) ScoresheetHistoryRecord(self::GAME, 'goal', 'add', ['num' => 1]));

        $refused = self::post([
            'save' => '1',
            'secretary' => 'Scorekeeper A',
            'team0' => 'H',
            'time0' => '99.99.99',
        ]);
        $this->assertStringContainsString('highlightError("time0")', $refused);

        // The corrected save must still be judged against the tokenless form.
        self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) self::tokenIn($refused)]);

        $this->assertNull(self::official());
    }

    public function testExcludedChangesDoNotRefuse(): void
    {
        $token = self::fetchToken();
        foreach (['timer', 'played', 'defense', 'mediaevent'] as $target) {
            $this->assertGreaterThan(0, (int) ScoresheetHistoryRecord(self::GAME, $target, 'update', ['x' => 1]));
        }
        $this->assertGreaterThan(0, (int) ScoresheetHistoryRecord(self::GAME, 'gameevent', 'update', ['type' => 'half_cap']));

        self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) $token]);

        $this->assertSame('Scorekeeper A', self::official());
    }

    public function testDisabledHistoryFailsOpen(): void
    {
        self::setHistoryDisabled(true);
        $tokenA = self::fetchToken();

        // The other operator saves through the app, which writes no history
        // while the flag is on.
        $tokenB = self::fetchToken();
        self::post(['save' => '1', 'secretary' => 'Scorekeeper B', 'history_token' => (string) $tokenB]);
        $this->assertSame('Scorekeeper B', self::official());

        self::post(['save' => '1', 'secretary' => 'Scorekeeper A', 'history_token' => (string) $tokenA]);

        $this->assertSame('Scorekeeper A', self::official());
    }

    private static function fetchToken(): int
    {
        [$status, $body] = self::request(
            '/index.php?view=user/addscoresheet&game=' . self::GAME,
            'GET',
            ['Cookie: ' . self::sessionCookie()],
        );
        self::assertStringContainsString(' 200 ', $status, 'unexpected status: ' . $status);
        return self::tokenIn($body);
    }

    private static function tokenIn(string $body): int
    {
        self::assertMatchesRegularExpression("/name='history_token' value='(\\d+)'/", $body);
        preg_match("/name='history_token' value='(\\d+)'/", $body, $m);
        return (int) $m[1];
    }

    private static function official(): ?string
    {
        self::flushQueryCaches();
        $value = DBQueryToValue(sprintf("SELECT official FROM uo_game WHERE game_id=%d", self::GAME));
        return $value === null ? null : (string) $value;
    }

    private static function setHistoryDisabled(bool $disabled): void
    {
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery(sprintf(
            "INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', '%s')",
            $disabled ? 'true' : 'false',
        ));
        self::flushQueryCaches();
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
