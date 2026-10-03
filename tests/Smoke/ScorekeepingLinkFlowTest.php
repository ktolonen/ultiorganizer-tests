<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Scorekeeping links over HTTP: opening scorekeeper/?t=<token>, logging in,
 * saving in Scorekeeper, and the desktop editor refusing the same session.
 * The app-source gate behind the grant only exists in a real request.
 *
 * Uses fixture game 701 and a throwaway account with no roles, both restored
 * afterwards. Assertions avoid translated text, because config-overrides
 * renders in fi_FI.
 */
final class ScorekeepingLinkFlowTest extends TestCase
{
    private const GAME = 701;
    private const KEEPER = 'linkkeeper';
    private const PASSWORD = 'harness-linkkeeper';

    private string $token = '';

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'game.functions.php'], 'pool_stack');
        self::restore();
        DBQuery(sprintf(
            "INSERT INTO uo_users (userid, password, name, last_login) VALUES ('%s', '%s', 'Link Keeper', NOW())",
            self::KEEPER,
            DBEscapeString(password_hash(self::PASSWORD, PASSWORD_DEFAULT)),
        ));
        $this->token = bin2hex(random_bytes(16));
        DBQuery(sprintf(
            "INSERT INTO uo_scorekeeper_token (token, game) VALUES ('%s', %d)",
            $this->token,
            self::GAME,
        ));
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', 'false')");
        self::flushQueryCaches();
    }

    protected function tearDown(): void
    {
        self::restore();
        LegacyApp::closeDatabaseConnection();
    }

    public function testLinkOpenedLoggedOutIsClaimedByTheLoginAndWorksOnlyInScorekeeper(): void
    {
        $this->get('/scorekeeper/index.php?view=login');
        [$status, , $headers] = $this->get('/scorekeeper/index.php?t=' . $this->token);
        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=login', self::header($headers, 'Location'));
        $this->assertSame('no-referrer', self::header($headers, 'Referrer-Policy'));
        $this->assertSame('no-store', self::header($headers, 'Cache-Control'));

        [$status, , $headers] = $this->post('/scorekeeper/index.php?view=login', [
            'myusername' => self::KEEPER,
            'mypassword' => self::PASSWORD,
            'login' => '1',
        ]);
        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=respgames&selseason=HRN2026', self::header($headers, 'Location'));

        [, $body] = $this->get('/scorekeeper/index.php?view=respgames');
        $this->assertStringContainsString('game=' . self::GAME, $body);

        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'save' => '1']);
        self::flushQueryCaches();
        $this->assertSame(['13', '9'], self::score());
        $this->assertSame(self::KEEPER, (string) DBQueryToValue(sprintf(
            "SELECT user_id FROM uo_scoresheet_history WHERE game=%d AND target='result' ORDER BY history_id DESC LIMIT 1",
            self::GAME,
        )));

        [, $body] = $this->post('/index.php?view=user/addresult&game=' . self::GAME, ['home' => '1', 'away' => '0', 'save' => '1']);
        $this->assertStringContainsString('Insufficient rights', $body);
        self::flushQueryCaches();
        $this->assertSame(['13', '9'], self::score());
    }

    public function testAnonymousLinkWorksOnlyWhileTheEventAllowsIt(): void
    {
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=1 WHERE season_id='HRN2026'");
        self::flushQueryCaches();

        $this->get('/scorekeeper/index.php?view=login');
        [$status, , $headers] = $this->get('/scorekeeper/index.php?t=' . $this->token);
        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=respgames&selseason=HRN2026', self::header($headers, 'Location'));

        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'save' => '1']);
        self::flushQueryCaches();
        $this->assertSame(['13', '9'], self::score());
        $row = DBQueryToRow(sprintf(
            "SELECT h.user_id, h.scorekeeper_token, t.token_id FROM uo_scoresheet_history h
                JOIN uo_scorekeeper_token t ON (t.token='%s')
            WHERE h.game=%d AND h.target='result' ORDER BY h.history_id DESC LIMIT 1",
            $this->token,
            self::GAME,
        ));
        $this->assertSame('anonymous', $row['user_id']);
        $this->assertSame((string) $row['token_id'], (string) $row['scorekeeper_token']);

        // The other fixture game is not covered by this link.
        [, $body] = $this->post('/scorekeeper/index.php?view=addresult&game=700', ['home' => '1', 'away' => '0', 'save' => '1']);
        $this->assertStringContainsString('Insufficient rights', $body);

        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0 WHERE season_id='HRN2026'");
        self::flushQueryCaches();
        [, $body] = $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '2', 'away' => '0', 'save' => '1']);
        $this->assertStringContainsString('Insufficient rights', $body);
        self::flushQueryCaches();
        $this->assertSame(['13', '9'], self::score());
    }

    public function testUnknownLinkIsRefusedWithoutAGrant(): void
    {
        $this->get('/scorekeeper/index.php?view=login');
        [$status, , $headers] = $this->get('/scorekeeper/index.php?t=' . str_repeat('0', 32));
        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=login', self::header($headers, 'Location'));
        [, $body] = $this->get('/scorekeeper/index.php?view=login');
        $this->assertStringContainsString("class='warning'", $body);
        $this->assertSame(0, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_scorekeeper_grant"));
    }

    /** @return array{0: string, 1: string} */
    private static function score(): array
    {
        $row = DBQueryToRow(sprintf("SELECT homescore, visitorscore FROM uo_game WHERE game_id=%d", self::GAME));
        return [(string) $row['homescore'], (string) $row['visitorscore']];
    }

    private static function restore(): void
    {
        DBQuery("DELETE FROM uo_scorekeeper_token");
        DBQuery("DELETE FROM uo_scorekeeper_grant");
        DBQuery("DELETE FROM uo_users WHERE userid='" . self::KEEPER . "'");
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0 WHERE season_id='HRN2026'");
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
        DBQuery(sprintf(
            "UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0,
                timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=%d",
            self::GAME,
        ));
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

    /** @param array<int, string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return null;
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function get(string $path): array
    {
        return $this->request($path, 'GET', []);
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function post(string $path, array $fields): array
    {
        return $this->request($path, 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /**
     * Sends the request with the cookies collected so far and keeps any new
     * ones, since opening a link and logging in both replace the session id.
     *
     * @return array{0: string, 1: string, 2: array<int, string>}
     */
    private function request(string $path, string $method, array $headers, string $content = ''): array
    {
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn($name, $value) => $name . '=' . $value,
                array_keys($this->cookies),
                $this->cookies,
            ));
        }
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
        $this->assertIsString($body, 'request failed: ' . $path);
        $this->assertDoesNotMatchRegularExpression('/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:/', $body);
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        return [$responseHeaders[0] ?? '', $body, $responseHeaders];
    }
}
