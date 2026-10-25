<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * Schema-driven fuzzing of every operation in the SUT's OpenAPI document
 * (GET /api/v1/openapi). Requests are generated from the request schemas and
 * then mutated: wrong types, null, boundary and huge numbers, unicode, long
 * strings, missing and extra fields, deep nesting, invalid JSON, a wrong
 * content type, and broken credentials.
 *
 * Properties asserted for every response:
 *  - the status is never 5xx (a documented 503 `busy` answer is the one exception);
 *  - the body is a JSON object, and a 4xx carries the Error envelope
 *    (status=error, error.code, error.message);
 *  - the body leaks no PHP diagnostics (Warning, Notice, Fatal error, Stack trace).
 *
 * The run is reproducible: UO_FUZZ_SEED fixes the seed (a failure prints it),
 * UO_FUZZ_ITERATIONS sets the requests per operation (default 60), and
 * UO_FUZZ_STATS=1 prints the status codes each operation received. Valid
 * credentials of the scorekeeping fixture are used so mutated bodies reach the
 * handlers instead of stopping at authentication; the fixture is reset for each
 * operation because a fuzzed finalize or upload changes game 701.
 */
final class ApiFuzzTest extends ScorekeepingApiTestCase
{
    /** Requests between fixture resets; a fuzzed finalize or upload would otherwise turn every later request into a 409. */
    private const RESET_EVERY = 10;
    private const GAMES = '700, 701';

    private int $seed;
    /** @var string[] anchored regexes of the documented paths */
    private array $routes = [];
    private int $requests = 0;

    /** @return array<string, array{string, string}> */
    public static function operations(): array
    {
        $doc = json_decode((string) file_get_contents(self::specPath()), true);
        $operations = [];
        foreach ($doc['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (is_array($operation) && isset($operation['responses'])) {
                    $operations[strtoupper($method) . ' ' . $path] = [strtoupper($method), $path];
                }
            }
        }
        return $operations;
    }

    private static function specPath(): string
    {
        $path = (getenv('UO_SUT_ROOT') ?: dirname(__DIR__, 2) . '/.runtime/webroot') . '/api/openapi.json';
        return is_file($path) ? $path : '/var/www/html/api/openapi.json';
    }

    #[DataProvider('operations')]
    public function testOperationSurvivesFuzzing(string $method, string $path): void
    {
        $this->seed = (int) (getenv('UO_FUZZ_SEED') ?: random_int(1, 2147483647));
        mt_srand($this->seed);
        $iterations = (int) (getenv('UO_FUZZ_ITERATIONS') ?: 60);
        $spec = json_decode((string) file_get_contents(self::specPath()), true);
        $operation = $spec['paths'][$path][strtolower($method)];
        $this->routes = $this->compileRoutes($spec);

        $tally = [];
        for ($i = 0; $i < $iterations; $i++) {
            if ($i > 0 && $i % self::RESET_EVERY === 0) {
                $this->tearDown();
                $this->setUp();
            }
            if ($i % self::RESET_EVERY === 0) {
                $this->warmUp();
            }
            [$url, $headers, $body] = $this->buildRequest($spec, $method, $path, $operation);
            if ($this->requests++ % 20 === 0) {
                DBQuery("DELETE FROM uo_api_rate_limit");
            }
            $before = $this->stateSnapshot();
            $response = self::request($method, $url, $headers, $body);
            $key = $response['status'] . (isset($response['json']['error']['code']) ? ':' . $response['json']['error']['code'] : '');
            $tally[$key] = ($tally[$key] ?? 0) + 1;
            $this->assertSafeResponse($response, $method, $url, $headers, $body, $i);
            $this->assertStateInvariants($response, $before, $method, $url, $headers, $body, $i);
        }
        if (getenv('UO_FUZZ_STATS')) {
            ksort($tally);
            fwrite(STDERR, sprintf("\nfuzz %-48s %s", "$method $path", json_encode($tally)));
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Gives each batch a different starting state so the write handlers reach
     * their success paths: an empty game, a game with rosters, a game with a
     * complete upload, or a game with rosters and one goal.
     */
    private function warmUp(): void
    {
        $mode = mt_rand(0, 3);
        if ($mode === 0) {
            return;
        }
        if ($mode === 2) {
            $token = self::entryContext()['token'];
            self::put(self::validDoc(), $token, 'warm-up');
            return;
        }
        DBQuery("INSERT INTO uo_played (player, game, num, accredited, captain) VALUES
            (802, 701, 7, 1, 0), (803, 701, 14, 1, 0), (800, 701, 8, 1, 0), (801, 701, 12, 1, 0)");
        if ($mode === 3) {
            $headers = self::auth() + ['Idempotency-Key' => 'warm-up', 'Content-Type' => 'application/json'];
            self::request('POST', '/api/v1/games/' . self::GAME . '/goals', $headers, json_encode([
                'side' => 'home', 'scorer_id' => 803, 'assist_id' => 802, 'callahan' => false, 'time' => 60,
            ]));
        }
    }

    /** Everything a game write can change for the two fixture games, as one comparable value. */
    private function stateSnapshot(): string
    {
        $parts = [];
        $parts[] = DBQueryToArrayUncached("SELECT game_id, homescore, visitorscore, hasstarted, isongoing, official, halftime, forfeit, timer_start, timer_pause_start, timer_paused_duration FROM uo_game WHERE game_id IN (" . self::GAMES . ") ORDER BY game_id");
        foreach (['uo_goal' => 'game, num', 'uo_played' => 'game, player', 'uo_timeout' => 'game, num', 'uo_spirit_timeout' => 'game, num', 'uo_gameevent' => 'game, num', 'uo_defense' => 'game, num', 'uo_scoresheet_history' => 'game, 1'] as $table => $order) {
            $parts[] = DBQueryToArrayUncached("SELECT * FROM $table WHERE game IN (" . self::GAMES . ") ORDER BY $order");
        }
        return md5((string) json_encode($parts));
    }

    /**
     * A refused request must leave no trace, and whatever a request did the
     * stored goals must stay a gapless sequence 1..n of players on the game roster.
 * A success needs a path the OpenAPI document lists, and on a game route a valid
 * app token and a link covering that game.
     */
    private function assertStateInvariants(array $response, string $before, string $method, string $url, array $headers, ?string $body, int $i): void
    {
        $context = sprintf("seed=%d iteration=%d\n%s %s\nheaders=%s\nbody=%s\n-> %d %s", $this->seed, $i, $method, $url, json_encode($headers), substr((string) $body, 0, 600), $response['status'], substr($response['raw'], 0, 300));
        $urlPath = (string) parse_url($url, PHP_URL_PATH);
        if ($response['status'] < 300) {
            $documented = false;
            foreach ($this->routes as $route) {
                $documented = $documented || preg_match($route, $urlPath) === 1;
            }
            $this->assertTrue($documented, "A path the OpenAPI document does not list answered with a success\n" . $context);
        }
        if ($response['status'] < 300 && preg_match('#^/api/v1/games/(\d+)/#', $url, $m)) {
            // Both credentials must be valid and the link must cover this very game; the fixture links cover 701 (game, field 501) and 700 (game, field 500).
            $covered = [self::LINK_GAME => 701, self::LINK_FIELD => 701, self::LINK_OTHER_GAME => 700, self::LINK_OTHER_FIELD => 700];
            $this->assertContains($headers['Authorization'] ?? '', ['Bearer ' . self::TOKEN_INSTALL, 'Bearer ' . self::TOKEN_EVENT], "A request succeeded without a valid app token\n" . $context);
            $this->assertSame((int) $m[1], $covered[$headers['X-Scorekeeping-Token'] ?? ''] ?? null, "A request succeeded for a game its scorekeeping link does not cover\n" . $context);
        }
        if ($method === 'GET') {
            $this->assertSame($before, $this->stateSnapshot(), "A GET changed stored state\n" . $context);
        }
        if ($response['status'] >= 400) {
            $this->assertSame($before, $this->stateSnapshot(), "A refused request changed stored state\n" . $context);
        }
        $nums = array_map('intval', array_column(DBQueryToArrayUncached("SELECT num FROM uo_goal WHERE game=701 ORDER BY num"), 'num'));
        $this->assertSame($nums === [] ? [] : range(1, count($nums)), $nums, "Goal numbers are not 1..n\n" . $context);
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_goal g WHERE g.game=701 AND
            ((g.scorer IS NOT NULL AND NOT EXISTS (SELECT 1 FROM uo_played p WHERE p.game=g.game AND p.player=g.scorer))
            OR (g.assist IS NOT NULL AND NOT EXISTS (SELECT 1 FROM uo_played p WHERE p.game=g.game AND p.player=g.assist)))"), "A goal names a player who is not on the game roster\n" . $context);
    }

    private function assertSafeResponse(array $response, string $method, string $url, array $headers, ?string $body, int $i): void
    {
        $context = sprintf(
            "seed=%d iteration=%d\n%s %s\nheaders=%s\nbody=%s\n-> %d %s",
            $this->seed,
            $i,
            $method,
            $url,
            json_encode($headers),
            substr((string) $body, 0, 600),
            $response['status'],
            substr($response['raw'], 0, 600),
        );
        $busy = $response['status'] === 503 && ($response['json']['error']['code'] ?? '') === 'busy';
        $this->assertTrue($response['status'] > 0 && ($response['status'] < 500 || $busy), "5xx or no response\n" . $context);
        $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error|Parse error|Stack trace)\b|on line \d+/', $response['raw'], "PHP diagnostic leaked\n" . $context);
        // Apache answers some hostile requests itself (encoded slashes, oversized lines); the SUT never ran.
        $apache = $response['status'] >= 400 && $response['status'] < 500 && str_contains($response['raw'], '<address>Apache/');
        if ($apache || $response['status'] === 204 || $response['status'] === 304) {
            return;
        }
        $this->assertStringContainsString('application/json', $response['headers']['content-type'] ?? '', "JSON body without a JSON content type\n" . $context);
        if ($response['status'] === 405) {
            $this->assertNotSame('', $response['headers']['allow'] ?? '', "405 without an Allow header\n" . $context);
        }
        $this->assertNotNull($response['json'], "Body is not a JSON object\n" . $context);
        if ($response['status'] >= 400) {
            $this->assertSame('error', $response['json']['status'] ?? null, "Error envelope\n" . $context);
            $this->assertIsString($response['json']['error']['code'] ?? null, "error.code\n" . $context);
            $this->assertIsString($response['json']['error']['message'] ?? null, "error.message\n" . $context);
        }
    }

    /** @return array{string, array<string, string>, ?string} */
    private function buildRequest(array $spec, string $method, string $path, array $operation): array
    {
        $params = $operation['parameters'] ?? [];
        $url = $path;
        $query = [];
        $headers = [];
        $secured = !empty($operation['security']);

        foreach ($params as $param) {
            $param = $this->deref($spec, $param);
            $value = $this->pickValue($spec, $param['schema'] ?? [], $param['name'], $param['in']);
            if ($param['in'] === 'path') {
                $url = str_replace('{' . $param['name'] . '}', rawurlencode($this->scalar($value)), $url);
            } elseif ($param['in'] === 'query') {
                if (!empty($param['required']) || $this->chance(60)) {
                    $query[$param['name']] = $this->scalar($value);
                }
            } elseif ($param['in'] === 'header' && $param['name'] !== 'X-Scorekeeping-Token') {
                if (!empty($param['required']) ? !$this->chance(2) : $this->chance(40)) {
                    $headers[$param['name']] = $this->headerSafe($this->scalar($value));
                }
            }
        }
        if ($secured) {
            $headers += $this->credentials();
        } elseif ($this->chance(30)) {
            $headers += $this->credentials();
        }
        if (!isset($headers['Authorization']) && !$secured) {
            $headers['Authorization'] = 'Bearer harness-api-token';
        }
        if ($query === [] && str_contains($path, '/api/v1/') && !str_contains($path, '{') && $this->chance(70)) {
            $query['event'] = $this->chance(70) ? 'HRN2026' : $this->scalar($this->junk());
        }
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $body = null;
        $content = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        if ($content !== null) {
            $headers['Content-Type'] = $this->chance(92) ? 'application/json' : $this->pick(['text/plain', 'application/x-www-form-urlencoded', '']);
            $semantic = $this->chance(65) ? $this->semanticBody(basename((string) ($content['$ref'] ?? '')), $url) : null;
            $value = $semantic ?? $this->generate($spec, $content, 0);
            // A valid body goes out unmutated half of the time: one corrupt node in twenty makes a whole upload fail validation.
            $body = $this->chance(8) ? $this->rawGarbage() : json_encode($semantic !== null && $this->chance(50) ? $semantic : $this->mutate($value, 0), JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $this->chance(30)) {
            $body = $this->rawGarbage();
        }
        return [$url, $headers, $body];
    }

    /**
     * A body that is valid for the current game state, built from what the
     * fixture and the database hold; mutate() then breaks one piece of it.
     * Schema-only generation cannot satisfy rules such as "scorer on the
     * side's roster" or "score equals the stored score", so without this
     * the success paths of the writes would hardly ever run.
     */
    private function semanticBody(string $schema, string $url): ?array
    {
        $sides = ['home' => [301, [802, 803]], 'away' => [300, [800, 801]]];
        switch ($schema) {
            case 'GameData':
                return self::validDoc();
            case 'SideRoster':
                $side = preg_match('#/rosters/(home|away)#', $url, $m) ? $m[1] : $this->pick(['home', 'away']);
                [$team, $ids] = $sides[$side];
                $players = [];
                foreach ($ids as $n => $id) {
                    $players[] = ['player_id' => $id, 'number' => 7 + $n, 'captain' => $n === 0, 'spirit_captain' => $n === 1];
                }
                return ['team_id' => $team, 'players' => array_slice($players, 0, mt_rand(0, 2))];
            case 'LiveGoal':
                $side = $this->pick(['home', 'away']);
                $ids = $sides[$side][1];
                shuffle($ids);
                $callahan = $this->chance(15);
                $last = (int) DBQueryToValueUncached("SELECT COALESCE(MAX(time), 0) FROM uo_goal WHERE game=701");
                return [
                    'side' => $side,
                    'scorer_id' => $ids[0],
                    'assist_id' => $callahan ? null : $ids[1],
                    'callahan' => $callahan,
                    'time' => $last + mt_rand(1, 120),
                ];
            case 'FinalScore':
                $row = DBQueryToRowUncached("SELECT homescore, visitorscore FROM uo_game WHERE game_id=701");
                return ['home' => (int) $row['homescore'], 'away' => (int) $row['visitorscore']];
        }
        return null;
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        $token = self::TOKEN_INSTALL;
        $link = self::LINK_GAME;
        if ($this->chance(8)) {
            $token = $this->pick(['', 'x', str_repeat('a', 5000), "tok\r\nX-Injected: 1", self::TOKEN_REVOKED, self::TOKEN_USER, self::TOKEN_OTHER_EVENT]);
        }
        if ($this->chance(8)) {
            $link = $this->pick(['', 'short', str_repeat('f', 33), str_repeat('G', 32), self::LINK_OTHER_GAME, "' OR 1=1 --", "0\0" . str_repeat('0', 30)]);
        }
        $headers = ['Authorization' => $this->chance(95) ? 'Bearer ' . $this->headerSafe($token) : $this->pick(['Bearer', 'Basic Zm9vOmJhcg==', $this->headerSafe($token)])];
        if ($this->chance(97)) {
            $headers['X-Scorekeeping-Token'] = $this->headerSafe($link);
        }
        return $headers;
    }

    /**
     * One regex per documented path: integer parameters match digits, enum
     * parameters match their values, any other parameter one non-empty segment.
     *
     * @return string[]
     */
    private function compileRoutes(array $spec): array
    {
        $routes = [];
        foreach ($spec['paths'] as $path => $item) {
            $pattern = preg_quote($path, '#');
            foreach ($item as $operation) {
                foreach (is_array($operation) ? ($operation['parameters'] ?? []) : [] as $param) {
                    $param = $this->deref($spec, $param);
                    if (($param['in'] ?? '') !== 'path') {
                        continue;
                    }
                    $schema = $this->deref($spec, $param['schema'] ?? []);
                    $class = isset($schema['enum']) ? '(?:' . implode('|', array_map(fn($v) => preg_quote((string) $v, '#'), $schema['enum'])) . ')'
                        : (($schema['type'] ?? '') === 'integer' ? '\\d+' : '[^/]+');
                    $pattern = str_replace(preg_quote('{' . $param['name'] . '}', '#'), $class, $pattern);
                }
            }
            $routes[] = '#^' . $pattern . '/?$#';
        }
        return $routes;
    }

    private function deref(array $spec, array $node): array
    {
        $guard = 0;
        while (isset($node['$ref']) && $guard++ < 20) {
            $target = $spec;
            foreach (array_slice(explode('/', $node['$ref']), 1) as $segment) {
                $target = $target[$segment] ?? [];
            }
            $node = $target;
        }
        return $node;
    }

    /** A value for a path/query/header parameter: mostly plausible, sometimes hostile. */
    private function pickValue(array $spec, array $schema, string $name, string $in): mixed
    {
        $schema = $this->deref($spec, $schema);
        if ($this->chance($in === 'path' ? 6 : ($in === 'header' ? 8 : 25))) {
            return $this->junk();
        }
        if ($in === 'query' && $this->chance(80)) {
            $hinted = match ($name) {
                'event' => $this->pick(['HRN2026', 'HRN2026', 'HRN2026', 'OTHER2026', 'hrn2026', 'HRN2026 ', 'HRN2026,OTHER2026']),
                'division' => $this->pick([100, 100, 101, 0, -1]),
                'pool' => $this->pick([200, 200, 201, 0, -1]),
                'pools' => $this->pick(['200', '200,201', '200,', ',', '200;201', '0', '-1', str_repeat('200,', 300)]),
                'team' => $this->pick([300, 301, 302, 0, -1]),
                'game' => $this->pick([700, 700, 701, 702, 0, -1, PHP_INT_MAX]),
                'group' => $this->pick(['all', 'Harness', '', '%', 'a,b']),
                default => null,
            };
            if ($hinted !== null) {
                return $hinted;
            }
        }
        if ($name === 'game_id') {
            return $this->pick(array_merge(array_fill(0, 12, self::GAME), [700, 700, 0, -1, 999999, PHP_INT_MAX]));
        }
        if ($name === 'num' && $in === 'path' && $this->chance(70)) {
            // Only the last goal can be deleted.
            return max(1, (int) DBQueryToValueUncached("SELECT COALESCE(MAX(num), 0) FROM uo_goal WHERE game=701"));
        }
        if ($name === 'Idempotency-Key') {
            return $this->chance(85) ? 'k' . mt_rand() : $this->pick(['key-1', 'key-1', str_repeat('k', 64), str_repeat('k', 65), '', 'sch' . "\u{fc}" . 'ssel']);
        }
        if ($name === 'If-Match') {
            if ($this->chance(65)) {
                return '"' . self::entryContext()['token'] . '"';
            }
            return $this->pick(['"' . str_repeat('a', 64) . '"', '*', 'W/"x"', '""', '"' . mt_rand() . '"']);
        }
        if (isset($schema['enum'])) {
            return $this->pick($schema['enum']);
        }
        return $this->generate($spec, $schema, 0, $name);
    }

    /** Builds a schema-valid value (best effort). */
    private function generate(array $spec, array $schema, int $depth, string $name = ''): mixed
    {
        $schema = $this->deref($spec, $schema);
        if ($depth > 6) {
            return null;
        }
        foreach (['oneOf', 'anyOf'] as $key) {
            if (!empty($schema[$key])) {
                return $this->generate($spec, $this->pick($schema[$key]), $depth + 1, $name);
            }
        }
        if (!empty($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $part) {
                $merged = array_replace_recursive($merged, $this->deref($spec, $part));
            }
            return $this->generate($spec, $merged, $depth + 1, $name);
        }
        if (isset($schema['enum'])) {
            return $this->pick($schema['enum']);
        }
        if (!empty($schema['nullable']) && $this->chance(10)) {
            return null;
        }
        switch ($schema['type'] ?? 'object') {
            case 'integer':
                $hinted = $this->hintedInteger($name);
                if ($hinted !== null) {
                    return $hinted;
                }
                $min = (int) ($schema['minimum'] ?? 0);
                $max = (int) ($schema['maximum'] ?? 1000);
                return mt_rand($min, max($min, min($max, $min + 2000)));
            case 'number':
                return mt_rand(0, 10000) / 100;
            case 'boolean':
                return (bool) mt_rand(0, 1);
            case 'string':
                if (($schema['format'] ?? '') === 'date-time') {
                    return '2026-06-01T14:00:00+03:00';
                }
                $length = max((int) ($schema['minLength'] ?? 1), 1);
                return substr($this->hex(40), 0, min($length + mt_rand(0, 8), (int) ($schema['maxLength'] ?? 64)));
            case 'array':
                $items = $schema['items'] ?? [];
                $out = [];
                for ($n = mt_rand((int) ($schema['minItems'] ?? 0), max((int) ($schema['minItems'] ?? 0), 3)); $n > 0; $n--) {
                    $out[] = $this->generate($spec, $items, $depth + 1, $name);
                }
                return $out;
            default:
                $out = [];
                $required = $schema['required'] ?? [];
                foreach ($schema['properties'] ?? [] as $name => $sub) {
                    if (in_array($name, $required, true) || $this->chance(70)) {
                        $out[$name] = $this->generate($spec, $sub, $depth + 1, $name);
                    }
                }
                return $out === [] ? new \stdClass() : $out;
        }
    }

    /** Ids the fixture knows, so mutated bodies reach the handlers' real checks. */
    private function hintedInteger(string $name): ?int
    {
        if (!$this->chance(85)) {
            return null;
        }
        return match ($name) {
            'scorer_id', 'assist_id', 'player_id' => $this->pick([800, 801, 802, 803]),
            'team_id', 'home_team_id', 'away_team_id' => $this->pick([300, 301]),
            'num' => mt_rand(1, 4),
            'number' => mt_rand(0, 99),
            'time' => $this->chance(15) ? $this->pick([0, 1, 32767, 32768, 65535, 86400, 86401]) : mt_rand(0, 2400),
            'home', 'away' => mt_rand(0, 4),
            'point_cap' => mt_rand(1, 15),
            'halftime' => $this->pick([900, 1800, 2400]),
            default => null,
        };
    }

    /** Randomly corrupts a generated value, recursively. */
    private function mutate(mixed $value, int $depth): mixed
    {
        if ($depth > 6) {
            return $value;
        }
        if (is_array($value)) {
            if ($value !== [] && $this->chance(8)) {
                unset($value[array_rand($value)]);
            }
            if ($this->chance(6)) {
                $value[$this->pick(['extra', '__proto__', '0', "k\u{e4}", ''])] = $this->junk();
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->chance(12) ? $this->junk() : $this->mutate($item, $depth + 1);
            }
            if ($this->chance(3)) {
                return $this->deepNesting(mt_rand(50, 400));
            }
            return $value;
        }
        return $this->chance(12) ? $this->junk() : $value;
    }

    private function junk(): mixed
    {
        return $this->pick([
            null, true, false, 0, -1, 1, 32767, 32768, -32768, 2147483647, 2147483648, PHP_INT_MAX, 1.5, -0.0, 1e308,
            '', ' ', '0', 'null', 'NaN', "' OR '1'='1", '1; DROP TABLE uo_game', '<script>alert(1)</script>',
            "\u{e4}\u{f6}\u{e5}", "\u{1F600}", "\0", "line\r\nbreak", str_repeat('A', 10000), '../../etc/passwd', '%00', '${jndi:ldap://x}',
            [], new \stdClass(), [[]], ['a' => ['b' => ['c' => 1]]], [null], ['home', 'away'],
        ]);
    }

    private function deepNesting(int $depth): mixed
    {
        $value = 1;
        for ($i = 0; $i < $depth; $i++) {
            $value = [$value];
        }
        return $value;
    }

    private function rawGarbage(): string
    {
        return $this->pick(['', '{', '{not json', '[', 'null', '"string"', '42', 'true', '[[[[[[[[', '{"a":', "\xff\xfe\x00", '{"side":"home",}', str_repeat('{"a":', 5000), $this->hex(32)]);
    }

    /** Capped so URLs and headers stay under Apache's own limits, which answer before the SUT runs. */
    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $text = (string) json_encode($value);
        } else {
            $text = (string) $value;
        }
        return substr($text, 0, 2000);
    }

    /** The client cannot send raw CR/LF/NUL, and Apache answers headers over its ~8KB limit itself, so neither reaches the SUT. */
    private function headerSafe(string $value): string
    {
        return substr(str_replace(["\r", "\n", "\0"], '', $value), 0, 2000);
    }

    private function hex(int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $out .= dechex(mt_rand());
        }
        return substr($out, 0, $length);
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    private function pick(array $values): mixed
    {
        return $values[array_rand($values)];
    }
}
