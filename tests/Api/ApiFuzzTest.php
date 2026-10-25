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
 * UO_FUZZ_ITERATIONS sets the requests per operation (default 60). Valid
 * credentials of the scorekeeping fixture are used so mutated bodies reach the
 * handlers instead of stopping at authentication; the fixture is reset for each
 * operation because a fuzzed finalize or upload changes game 701.
 */
final class ApiFuzzTest extends ScorekeepingApiTestCase
{
    private int $seed;
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

        for ($i = 0; $i < $iterations; $i++) {
            [$url, $headers, $body] = $this->buildRequest($spec, $method, $path, $operation);
            if ($this->requests++ % 20 === 0) {
                DBQuery("DELETE FROM uo_api_rate_limit");
            }
            $response = self::request($method, $url, $headers, $body);
            $this->assertSafeResponse($response, $method, $url, $headers, $body, $i);
        }
        $this->addToAssertionCount(1);
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
                if (!empty($param['required']) ? !$this->chance(5) : $this->chance(40)) {
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
            $body = $this->chance(8) ? $this->rawGarbage() : json_encode($this->mutate($this->generate($spec, $content, 0), 0), JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $this->chance(30)) {
            $body = $this->rawGarbage();
        }
        return [$url, $headers, $body];
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
        if ($this->chance(25)) {
            return $this->junk();
        }
        if ($name === 'game_id') {
            return $this->pick([self::GAME, self::GAME, self::GAME, 700, 0, -1, 999999, PHP_INT_MAX]);
        }
        if ($name === 'Idempotency-Key') {
            return $this->pick(['k' . mt_rand(), 'key-1', str_repeat('k', 64), str_repeat('k', 65), '', 'sch' . "\u{fc}" . 'ssel']);
        }
        if ($name === 'If-Match') {
            return $this->pick(['"' . str_repeat('a', 64) . '"', '*', 'W/"x"', '""', '"' . mt_rand() . '"']);
        }
        if (isset($schema['enum'])) {
            return $this->pick($schema['enum']);
        }
        return $this->generate($spec, $schema, 0);
    }

    /** Builds a schema-valid value (best effort). */
    private function generate(array $spec, array $schema, int $depth): mixed
    {
        $schema = $this->deref($spec, $schema);
        if ($depth > 6) {
            return null;
        }
        foreach (['oneOf', 'anyOf'] as $key) {
            if (!empty($schema[$key])) {
                return $this->generate($spec, $this->pick($schema[$key]), $depth + 1);
            }
        }
        if (!empty($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $part) {
                $merged = array_replace_recursive($merged, $this->deref($spec, $part));
            }
            return $this->generate($spec, $merged, $depth + 1);
        }
        if (isset($schema['enum'])) {
            return $this->pick($schema['enum']);
        }
        if (!empty($schema['nullable']) && $this->chance(10)) {
            return null;
        }
        switch ($schema['type'] ?? 'object') {
            case 'integer':
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
                    $out[] = $this->generate($spec, $items, $depth + 1);
                }
                return $out;
            default:
                $out = [];
                $required = $schema['required'] ?? [];
                foreach ($schema['properties'] ?? [] as $name => $sub) {
                    if (in_array($name, $required, true) || $this->chance(70)) {
                        $out[$name] = $this->generate($spec, $sub, $depth + 1);
                    }
                }
                return $out === [] ? new \stdClass() : $out;
        }
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
