<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Games in a hidden pool stay out of the public schedule outputs.
 *
 * games.php and ical.php pass $onlypublic to TimetableGames(), which gates
 * games on their pool's (playoff root's) visible flag (#84). The test hides the
 * fixture's only pool and compares /api/v1/games with the public schedule page.
 */
final class ApiPoolVisibilityTest extends TestCase
{
    private const TOKEN = 'harness-api-token';
    private const POOL = 200;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
    }

    protected function tearDown(): void
    {
        DBQuery(sprintf("UPDATE uo_pool SET visible=1 WHERE pool_id=%d", self::POOL));
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testVisiblePoolGamesAreListed(): void
    {
        $this->assertSame([700, 701], self::apiGameIds());
        $this->assertStringContainsString('Tampere Tempest', self::publicSchedule());
    }

    public function testHiddenPoolGamesAreLeftOutOfThePublicSchedulePage(): void
    {
        // Reference behaviour: the public schedule hides the pool's games.
        DBQuery(sprintf("UPDATE uo_pool SET visible=0 WHERE pool_id=%d", self::POOL));

        $this->assertStringNotContainsString('Tampere Tempest', self::publicSchedule());
    }

    public function testHiddenPoolGamesAreLeftOutOfTheApi(): void
    {
        DBQuery(sprintf("UPDATE uo_pool SET visible=0 WHERE pool_id=%d", self::POOL));

        $this->assertSame([], self::apiGameIds());
    }

    /** @return list<int> */
    private static function apiGameIds(): array
    {
        $body = self::get('/api/v1/games?event=HRN2026&token=' . self::TOKEN);
        $payload = json_decode($body, true);
        self::assertIsArray($payload, 'not JSON: ' . substr($body, 0, 300));
        $ids = array_map(static fn(array $game): int => $game['game_id'], $payload['data']['games']);
        sort($ids);
        return $ids;
    }

    private static function publicSchedule(): string
    {
        return self::get('/index.php?view=games&season=HRN2026&filter=all');
    }

    private static function get(string $path): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '', $path);
        return (string) $body;
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
