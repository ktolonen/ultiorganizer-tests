<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiEndpointsContractTest extends TestCase
{
    private const TOKEN = 'harness-api-token';

    public function testOpenApiSpecIsPublicAndDocumentsCorePaths(): void
    {
        $response = self::apiGet('/api/v1/openapi');

        self::assertJsonResponse('openapi', $response, 200);
        $payload = self::decodeJson($response['body']);

        $this->assertSame('3.0.3', $payload['openapi']);
        $this->assertArrayHasKey('/api/v1/events', $payload['paths']);
        $this->assertArrayHasKey('/api/v1/teams', $payload['paths']);
        $this->assertArrayHasKey('/api/v1/games', $payload['paths']);
        $this->assertArrayHasKey('/api/v1/gameplay', $payload['paths']);
    }

    public function testApiRequiresTokenForProtectedResources(): void
    {
        $response = self::apiGet('/api/v1/events');

        self::assertJsonResponse('events-missing-token', $response, 401);
        $payload = self::decodeJson($response['body']);

        $this->assertSame('error', $payload['status']);
        $this->assertSame('missing_token', $payload['error']['code']);
    }

    public function testApiRejectsInvalidBearerToken(): void
    {
        $response = self::apiGet('/api/v1/events', 'not-a-valid-token');

        self::assertJsonResponse('events-invalid-token', $response, 401);
        $payload = self::decodeJson($response['body']);

        $this->assertSame('error', $payload['status']);
        $this->assertSame('invalid_token', $payload['error']['code']);
    }

    public function testEventsEndpointReturnsPublicFixtureEvent(): void
    {
        $payload = self::authorizedJson('/api/v1/events');

        $this->assertSame('ok', $payload['status']);
        $event = self::firstRowWithValue($payload['data']['events'], 'event_id', 'HRN2026');
        $this->assertNotNull($event);
        $this->assertSame('Harness Invitational 2026', $event['name']);
        $this->assertSame(1, $event['api_public']);
    }

    public function testTeamsEndpointReturnsFixtureTeams(): void
    {
        $payload = self::authorizedJson('/api/v1/teams?event=HRN2026');

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('HRN2026', $payload['data']['event']['event_id']);
        $division = $payload['data']['divisions'][0] ?? null;
        $this->assertIsArray($division);
        $this->assertSame('Open', $division['name']);
        $team = self::firstRowWithValue($division['teams'], 'team_id', 300);
        $this->assertNotNull($team);
        $this->assertSame('Helsinki Heat', $team['name']);
        $this->assertSame('HEAT', $team['abbreviation']);
    }

    public function testDivisionsEndpointReturnsFixturePool(): void
    {
        $payload = self::authorizedJson('/api/v1/divisions?event=HRN2026');

        $this->assertSame('ok', $payload['status']);
        $division = $payload['data']['divisions'][0] ?? null;
        $this->assertIsArray($division);
        $this->assertSame(100, $division['division_id']);
        $this->assertSame('Open', $division['name']);
        $pool = self::firstRowWithValue($division['pools'], 'pool_id', 200);
        $this->assertNotNull($pool);
        $this->assertSame('Pool A', $pool['name']);
    }

    public function testGamesEndpointReturnsFixtureGames(): void
    {
        $payload = self::authorizedJson('/api/v1/games?event=HRN2026&filter=all&group=all');

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('HRN2026', $payload['data']['event_id']);
        $game = self::firstRowWithValue($payload['data']['games'], 'game_id', 700);
        $this->assertNotNull($game);
        $this->assertSame('Helsinki Heat', $game['teams']['home']['name']);
        $this->assertSame('Tampere Tempest', $game['teams']['away']['name']);
        $this->assertSame(15, $game['scores']['home']);
        $this->assertSame(11, $game['scores']['away']);
    }

    public function testGameplayEndpointReturnsFixtureGameDetails(): void
    {
        $payload = self::authorizedJson('/api/v1/gameplay?game=700');

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(700, $payload['data']['game']['game_id']);
        $this->assertSame('Helsinki Heat', $payload['data']['teams']['home']['name']);
        $this->assertSame('Tampere Tempest', $payload['data']['teams']['away']['name']);
        $this->assertSame(15, $payload['data']['game']['scores']['home']);
        $this->assertSame(11, $payload['data']['game']['scores']['away']);
        $this->assertNotEmpty($payload['data']['goals']);
    }

    public function testGameplayEndpointReportsCapEventsAsNeutralWithTarget(): void
    {
        // Caps belong to neither team, so the gameplay feed reports team=null and
        // adds the cap target; every other event type keeps a home/away team and
        // carries no target key.
        $payload = self::authorizedJson('/api/v1/gameplay?game=700');

        $events = $payload['data']['events'];
        $this->assertCount(3, $events);

        $byType = [];
        foreach ($events as $event) {
            $byType[$event['type']] = $event;
        }
        $this->assertSame(['half_cap', 'turnover', 'time_cap'], array_keys($byType));

        $this->assertNull($byType['half_cap']['team']);
        $this->assertSame(400, $byType['half_cap']['time']);
        $this->assertSame(9, $byType['half_cap']['target']);

        $this->assertNull($byType['time_cap']['team']);
        $this->assertSame(900, $byType['time_cap']['time']);
        $this->assertSame(13, $byType['time_cap']['target']);

        // Contrast in the same payload: a non-cap event keeps its team and gets
        // no target key at all, so the nulls above are the cap rule and not a
        // feed that stopped attributing events.
        $this->assertSame('home', $byType['turnover']['team']);
        $this->assertSame(500, $byType['turnover']['time']);
        $this->assertArrayNotHasKey('target', $byType['turnover']);
    }

    public function testGameplayEndpointReportsCallahansPerPlayer(): void
    {
        // Fixture goal 3 is a callahan by home player 801, so the two home
        // players report different callahan counts in one payload. Asserting
        // both pins the serialization to the scoreboard column: an adapter that
        // hard-codes the field, or reads the wrong one, cannot produce a 1 and a
        // 0 for the right players. Each still scored once, so a callahan stays
        // inside the goal count rather than replacing it.
        $payload = self::authorizedJson('/api/v1/gameplay?game=700');

        $home = $payload['data']['scoreboard']['home'];
        $this->assertCount(2, $home);

        $byPlayer = [];
        foreach ($home as $player) {
            $this->assertArrayHasKey('callahans', $player);
            $byPlayer[(int) $player['player_id']] = $player;
        }
        $playerIds = array_keys($byPlayer);
        sort($playerIds);
        $this->assertSame([800, 801], $playerIds);

        $this->assertSame(1, $byPlayer[801]['callahans']);
        $this->assertSame(1, $byPlayer[801]['goals']);
        $this->assertSame(0, $byPlayer[800]['callahans']);
        $this->assertSame(1, $byPlayer[800]['goals']);
    }

    public function testGameplayEndpointFlagsTheCallahanGoal(): void
    {
        // The per-goal `callahan` field is a separate serialization from the
        // scoreboard counts above, off the goal row rather than the board.
        $payload = self::authorizedJson('/api/v1/gameplay?game=700');

        $goals = $payload['data']['goals'];
        $byNum = [];
        foreach ($goals as $goal) {
            $byNum[(int) $goal['num']] = $goal;
        }
        $this->assertSame([1, 2, 3, 4], array_keys($byNum));

        $this->assertSame(1, $byNum[3]['callahan']);
        // Contrast in the same payload: the other three goals stay 0, so the 1
        // above is the row's flag and not a field that is always set.
        $this->assertSame(0, $byNum[1]['callahan']);
        $this->assertSame(0, $byNum[2]['callahan']);
        $this->assertSame(0, $byNum[4]['callahan']);
    }

    private static function authorizedJson(string $path): array
    {
        $response = self::apiGet($path, self::TOKEN);
        self::assertJsonResponse($path, $response, 200);
        return self::decodeJson($response['body']);
    }

    private static function apiGet(string $path, ?string $token = null): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $errorLog = getenv('UO_APACHE_ERROR_LOG') ?: '/var/log/apache2/error.log';
        $beforeSize = is_file($errorLog) ? filesize($errorLog) : 0;

        if ($token !== null) {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        }

        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $body = is_string($response) ? $response : '';

        clearstatcache(true, $errorLog);
        $afterSize = is_file($errorLog) ? filesize($errorLog) : 0;
        $logExcerpt = '';
        if ($afterSize > $beforeSize && is_file($errorLog)) {
            $handle = fopen($errorLog, 'rb');
            if ($handle !== false) {
                fseek($handle, $beforeSize);
                $logExcerpt = stream_get_contents($handle) ?: '';
                fclose($handle);
            }
        }

        return [
            'body' => $body,
            'headers' => $responseHeaders,
            'status_code' => self::parseStatusCode($responseHeaders[0] ?? ''),
            'status_line' => $responseHeaders[0] ?? '',
            'content_type' => self::headerValue($responseHeaders, 'Content-Type'),
            'log_excerpt' => $logExcerpt,
            'request_path' => $path,
            'response_failed' => $response === false,
        ];
    }

    private static function assertJsonResponse(string $id, array $response, int $expectedStatus): void
    {
        $bodyIssue = preg_match('/Fatal error|Parse error|Warning|Notice/i', $response['body']) === 1;
        $logIssue = preg_match('/PHP (Fatal error|Parse error|Warning|Notice)/i', $response['log_excerpt']) === 1;

        if ($response['response_failed'] || $response['status_code'] !== $expectedStatus || $bodyIssue || $logIssue) {
            self::fail('API_FAILURE: ' . json_encode([
                'id' => $id,
                'request_path' => $response['request_path'],
                'expected_status' => $expectedStatus,
                'status_code' => $response['status_code'],
                'status_line' => $response['status_line'],
                'content_type' => $response['content_type'],
                'response_snippet' => self::snippet($response['body']),
                'log_excerpt' => self::snippet($response['log_excerpt']),
            ], JSON_UNESCAPED_SLASHES));
        }

        self::assertSame($expectedStatus, $response['status_code']);
        self::assertStringContainsString('application/json', strtolower($response['content_type']));
    }

    private static function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'Response body should decode as JSON: ' . self::snippet($body));
        return $decoded;
    }

    private static function firstRowWithValue(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row[$key]) && (string) $row[$key] === (string) $value) {
                return $row;
            }
        }
        return null;
    }

    private static function parseStatusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\b/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }
        return 0;
    }

    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }

    private static function snippet(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 400);
    }
}
