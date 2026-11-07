<?php

declare(strict_types=1);

namespace UltiorganizerHarness\Support;

use PHPUnit\Framework\TestCase;

/**
 * Shared fixture and HTTP client for the scorekeeping app API cases
 * (GET scorekeeping/games, GET games/{id}/entry-context, PUT games/{id}/game-data).
 *
 * The cases drive the SUT over HTTP like a third-party app and read the
 * database in-process. They skip when the SUT lacks the endpoints (the OpenAPI
 * document does not list them), never on a 5xx.
 *
 * Fixture: game 701 (Tampere Tempest 301 at home against Helsinki Heat 300)
 * and game 700 are moved to today in the event's timezone so the scorekeeping
 * links cover them; everything is restored in tearDown(). App tokens use ids
 * 910-914 and links 920-923.
 */
abstract class ScorekeepingApiTestCase extends TestCase
{
    protected const GAME = 701;
    protected const SEASON = 'HRN2026';
    protected const TOKEN_INSTALL = 'sk-install-token';
    protected const TOKEN_REVOKED = 'sk-revoked-token';
    protected const TOKEN_EVENT = 'sk-event-token';
    protected const TOKEN_OTHER_EVENT = 'sk-other-event-token';
    protected const TOKEN_USER = 'sk-user-token';
    protected const ID_INSTALL = 910;
    protected const LINK_GAME = 'cccccccccccccccccccccccccccc0701';
    protected const LINK_FIELD = 'cccccccccccccccccccccccccccc0501';
    protected const LINK_OTHER_GAME = 'cccccccccccccccccccccccccccc0700';
    protected const LINK_OTHER_FIELD = 'cccccccccccccccccccccccccccc0500';
    protected const LINK_ID_GAME = 920;

    /** @var array<int, array<string, mixed>> original rows restored in tearDown */
    private array $savedTeamPool = [];
    private array $savedTeams = [];
    private array $savedPools = [];
    private bool $fixtureInstalled = false;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['api.functions.php'], 'database_with_common');
        if (!self::sutHasEndpoints()) {
            $this->markTestSkipped('The SUT has no scorekeeping app API endpoints.');
        }
        self::cleanUp();
        $this->installFixture();
        $this->fixtureInstalled = true;
    }

    protected function tearDown(): void
    {
        if ($this->fixtureInstalled) {
            $this->restorePool();
            self::cleanUp();
            self::flushQueryCaches();
        }
        LegacyApp::closeDatabaseConnection();
    }

    /**
     * Whether the SUT has the clock, undo, goal correction and note writes, and
     * with them the entry context's clock, note and context-hash ETag, goals/{num}
     * PATCH, and replays answered before the coverage and event checks.
     */
    protected static function sutHasCorrections(): bool
    {
        static $has = null;
        if ($has === null) {
            $paths = self::request('GET', '/api/v1/openapi')['json']['paths'] ?? [];
            $has = isset($paths['/api/v1/games/{game_id}/clock']);
        }
        return $has;
    }

    /** Skips only when the SUT answers but does not list the endpoints; a 5xx fails the case. */
    private static function sutHasEndpoints(): bool
    {
        $response = self::request('GET', '/api/v1/openapi');
        if ($response['status'] >= 500 || $response['status'] === 0) {
            self::fail('The OpenAPI probe failed with status ' . $response['status'] . ': ' . substr($response['raw'], 0, 300));
        }
        $paths = $response['json']['paths'] ?? null;
        return $response['status'] === 200 && is_array($paths)
            && isset($paths['/api/v1/games/{game_id}/game-data'], $paths['/api/v1/games/{game_id}/entry-context'], $paths['/api/v1/scorekeeping/games']);
    }

    private function installFixture(): void
    {
        $this->savedTeamPool = DBQueryToArrayUncached("SELECT team, pool, rank, activerank FROM uo_team_pool WHERE pool=200");
        $this->savedTeams = DBQueryToArrayUncached("SELECT team_id, rank, activerank FROM uo_team WHERE series=100");
        $this->savedPools = DBQueryToArrayUncached("SELECT pool_id, played FROM uo_pool WHERE pool_id=200");

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
        DBQuery("UPDATE uo_game SET time='$today 14:00:00' WHERE game_id=701");
        DBQuery("UPDATE uo_game SET time='$today 10:00:00' WHERE game_id=700");
        DBQuery("UPDATE uo_reservation SET starttime='$today 14:00:00', endtime='$today 15:30:00', date='$today 00:00:00' WHERE id=501");
        DBQuery("UPDATE uo_reservation SET starttime='$today 10:00:00', endtime='$today 11:30:00', date='$today 00:00:00' WHERE id=500");

        foreach ([
            [910, self::TOKEN_INSTALL, 'installation', null, 0],
            [911, self::TOKEN_REVOKED, 'installation', null, 1],
            [912, self::TOKEN_EVENT, 'season', self::SEASON, 0],
            [913, self::TOKEN_OTHER_EVENT, 'season', 'OTHER2026', 0],
            [914, self::TOKEN_USER, 'user', 'admin', 0],
        ] as [$id, $token, $scope, $scopeId, $revoked]) {
            DBQuery(sprintf(
                "INSERT INTO uo_api_token (token_id, token_hash, token_value, label, scope_type, scope_id, revoked)
                 VALUES (%d, '%s', '%s', 'Scorekeeping test', '%s', %s, %d)",
                $id,
                hash('sha256', $token),
                $token,
                $scope,
                $scopeId === null ? 'NULL' : "'" . $scopeId . "'",
                $revoked,
            ));
        }
        foreach ([
            [920, self::LINK_GAME, 701, 'NULL'],
            [921, self::LINK_FIELD, 'NULL', 501],
            [922, self::LINK_OTHER_GAME, 700, 'NULL'],
            [923, self::LINK_OTHER_FIELD, 'NULL', 500],
        ] as [$id, $token, $game, $reservation]) {
            DBQuery(sprintf(
                "INSERT INTO uo_scorekeeper_token (token_id, token, game, reservation) VALUES (%d, '%s', %s, %s)",
                $id,
                $token,
                $game,
                $reservation,
            ));
        }
    }

    private static function cleanUp(): void
    {
        foreach (['skapi_fail', 'skapi_once'] as $trigger) {
            DBQuery("DROP TRIGGER IF EXISTS $trigger");
        }
        DBQuery("DROP TABLE IF EXISTS skapi_flag");
        DBQuery("DELETE FROM uo_api_rate_limit");
        DBQuery("DELETE FROM uo_scorekeeper_token WHERE token_id BETWEEN 920 AND 929");
        DBQuery("DELETE FROM uo_api_token WHERE token_id BETWEEN 910 AND 919");
        DBQuery("DELETE FROM uo_api_receipt WHERE game IN (700, 701)");
        DBQuery("DELETE FROM uo_event_log WHERE category='security' AND description='invalid scorekeeping link'");
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("DELETE FROM uo_comment WHERE type=4 AND id='701'");
        foreach (['uo_goal', 'uo_played', 'uo_timeout', 'uo_spirit_timeout', 'uo_gameevent', 'uo_defense', 'uo_scoresheet_history'] as $table) {
            DBQuery("DELETE FROM $table WHERE game=701");
        }
        DBQuery("DELETE FROM uo_scoresheet_history WHERE game=700");
        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0, halftime=35, official=NULL, forfeit=0,
            reservation=501, timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0, time='2026-06-01 14:00:00' WHERE game_id=701");
        DBQuery("UPDATE uo_game SET reservation=500, time='2026-06-01 10:00:00' WHERE game_id=700");
        DBQuery("UPDATE uo_reservation SET starttime='2026-06-01 14:00:00', endtime='2026-06-01 15:30:00', date='2026-06-01 00:00:00' WHERE id=501");
        DBQuery("UPDATE uo_reservation SET starttime='2026-06-01 10:00:00', endtime='2026-06-01 11:30:00', date='2026-06-01 00:00:00' WHERE id=500");
        DBQuery("UPDATE uo_season SET public_event=1, api_public=1, event_readonly=0, maintenance_mode=0, hide_time_on_scoresheet=0,
            require_accreditation=0, spiritmode=1003 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_player SET accredited=1 WHERE player_id IN (800, 801, 802, 803)");
    }

    private function restorePool(): void
    {
        foreach ($this->savedTeamPool as $row) {
            DBQuery(sprintf("UPDATE uo_team_pool SET rank=%d, activerank=%d WHERE team=%d AND pool=%d", $row['rank'], $row['activerank'], $row['team'], $row['pool']));
        }
        foreach ($this->savedTeams as $row) {
            DBQuery(sprintf("UPDATE uo_team SET rank=%s, activerank=%s WHERE team_id=%d", $row['rank'] ?? 'NULL', $row['activerank'] ?? 'NULL', $row['team_id']));
        }
        foreach ($this->savedPools as $row) {
            DBQuery(sprintf("UPDATE uo_pool SET played=%d WHERE pool_id=%d", $row['played'], $row['pool_id']));
        }
    }

    protected static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, json: ?array, raw: string}
     */
    protected static function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $http = ['method' => $method, 'header' => implode("\r\n", $lines), 'ignore_errors' => true, 'timeout' => 30];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $raw = (string) file_get_contents($baseUrl . $path, false, stream_context_create(['http' => $http]));
        $status = 0;
        $parsed = [];
        foreach ($http_response_header ?? [] as $i => $line) {
            if ($i === 0 && preg_match('/\s(\d{3})\b/', $line, $m)) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }
        $json = json_decode($raw, true);
        return ['status' => $status, 'headers' => $parsed, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    /** @return array<string, string> */
    protected static function auth(string $appToken = self::TOKEN_INSTALL, ?string $link = self::LINK_GAME): array
    {
        $headers = ['Authorization' => 'Bearer ' . $appToken];
        if ($link !== null) {
            $headers['X-Scorekeeping-Token'] = $link;
        }
        return $headers;
    }

    /** @return array{status: int, headers: array<string, string>, json: ?array, raw: string} */
    protected static function get(string $path, ?array $headers = null): array
    {
        return self::request('GET', $path, $headers ?? self::auth());
    }

    protected static function entryContext(): array
    {
        $response = self::get('/api/v1/games/' . self::GAME . '/entry-context');
        self::assertSame(200, $response['status'], $response['raw']);
        return $response['json']['data'];
    }

    /**
     * @param array<string, string> $extra headers that replace the defaults
     * @return array{status: int, headers: array<string, string>, json: ?array, raw: string}
     */
    protected static function put(array $doc, ?string $token, string $key = 'key-1', array $extra = [], ?string $rawBody = null): array
    {
        $headers = self::auth() + [
            'Idempotency-Key' => $key,
            'Content-Type' => 'application/json',
        ];
        if ($token !== null) {
            $headers['If-Match'] = '"' . $token . '"';
        }
        foreach ($extra as $name => $value) {
            if ($value === null) {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }
        return self::request('PUT', '/api/v1/games/' . self::GAME . '/game-data', $headers, $rawBody ?? json_encode($doc));
    }

    /** A document that is valid for the unplayed fixture game. */
    protected static function validDoc(): array
    {
        $player = static fn(int $id, int $number, bool $captain = false, bool $spirit = false) => [
            'player_id' => $id, 'number' => $number, 'captain' => $captain, 'spirit_captain' => $spirit,
        ];
        return [
            'version' => 1,
            'home_team_id' => 301,
            'away_team_id' => 300,
            'rosters' => [
                'home' => [$player(802, 7, true), $player(803, 14)],
                'away' => [$player(800, 8), $player(801, 12, false, true)],
            ],
            'goals' => [
                ['side' => 'home', 'scorer_id' => 803, 'assist_id' => 802, 'callahan' => false, 'time' => 60],
                ['side' => 'away', 'scorer_id' => 800, 'assist_id' => 801, 'callahan' => false, 'time' => 120],
                ['side' => 'home', 'scorer_id' => 802, 'assist_id' => null, 'callahan' => true, 'time' => 180],
            ],
            'timeouts' => ['home' => [['time' => 300]], 'away' => []],
            'spirit_stoppages' => ['home' => [['time' => 400]], 'away' => []],
            'result' => ['home' => 2, 'away' => 1, 'state' => 'ongoing'],
            'starting_offence' => 'home',
            'halftime' => 1800,
            'official' => 'Official One',
        ];
    }

    protected static function dbCount(string $sql): int
    {
        return (int) DBQueryToValueUncached($sql);
    }

    protected static function assertError(array $response, int $status, string $code): void
    {
        self::assertSame($status, $response['status'], $response['raw']);
        self::assertSame('error', $response['json']['status'] ?? null, $response['raw']);
        self::assertSame($code, $response['json']['error']['code'] ?? null, $response['raw']);
    }
}
