<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * A season-scoped token calling /games without naming an event.
 *
 * api_resolve_season_id() falls back to the token's scope season when no event
 * was requested, but api_games_context() has already filled in CurrentSeason()
 * by then, so the fallback never fires. The fixture token is scoped to the
 * current season, which hides this; the test seeds a second public event and a
 * token scoped to it.
 */
final class ApiSeasonScopedTokenTest extends TestCase
{
    private const SEASON = 'HRNSCOPE';
    private const TOKEN = 'harness-scope-token';
    private const TOKEN_ID = 901;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['api.functions.php'], 'database_with_common');
        self::cleanUp();
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', 'Scope Cup', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 1, 1, 'Europe/Helsinki', 1003)",
            self::SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_api_token (token_id, token_hash, token_value, label, scope_type, scope_id, revoked)
             VALUES (%d, '%s', '%s', 'Scope test', 'season', '%s', 0)",
            self::TOKEN_ID,
            ApiHashToken(self::TOKEN),
            self::TOKEN,
            self::SEASON,
        ));
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testNamedEventWorksForTheScopedToken(): void
    {
        // Precondition: the seeded event and token are valid together.
        [$status, $payload] = self::apiGet('/api/v1/games?event=' . self::SEASON);

        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame(self::SEASON, $payload['data']['event_id']);
    }

    public function testGamesWithoutAnEventDefaultToTheTokensSeason(): void
    {
        [$status, $payload] = self::apiGet('/api/v1/games');

        $this->assertSame(200, $status, json_encode($payload));
        $this->assertSame(self::SEASON, $payload['data']['event_id']);
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_api_token WHERE token_id=%d", self::TOKEN_ID));
        DBQuery(sprintf("DELETE FROM uo_api_rate_limit WHERE rate_key LIKE '%s|%%'", ApiHashToken(self::TOKEN)));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::SEASON));
    }

    /** @return array{0: int, 1: array} */
    private static function apiGet(string $path): array
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'header' => 'Authorization: Bearer ' . self::TOKEN,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\b/', $statusLine, $m);
        $decoded = json_decode((string) $body, true);
        self::assertIsArray($decoded, 'not JSON: ' . substr((string) $body, 0, 300));
        return [(int) ($m[1] ?? 0), $decoded];
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
