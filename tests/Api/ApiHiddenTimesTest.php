<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * /api/v1/gameplay and an event's hide_time_on_scoresheet flag.
 *
 * With the flag on, the score-sheet entry flows do not take point times: they
 * synthesize increasing values (docs/scoresheet.md, "Time visibility flag"),
 * and gameplay.php, mobile/gameplay.php, scorekeeper/gameplay.php and the RSS
 * game feed suppress times and the time-on-offence statistics. The test turns
 * the flag on for the fixture event and reads fixture game 700.
 */
final class ApiHiddenTimesTest extends TestCase
{
    private const TOKEN = 'harness-api-token';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=0 WHERE season_id='HRN2026'");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testRecordedTimesAreReturnedWhenTheEventShowsThem(): void
    {
        $data = self::gameplay();

        $this->assertNotSame([], self::goalTimes($data));
        $this->assertGreaterThan(0, $data['statistics']['time_on_offence']['home_seconds']);
    }

    public function testSynthesizedTimesAreNotReturnedWhenTheEventHidesThem(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");

        $data = self::gameplay();

        $this->assertSame([], self::goalTimes($data));
        $this->assertEmpty($data['statistics']['time_on_offence'] ?? null);
    }

    /** @return list<int> the non-null goal times the endpoint reports */
    private static function goalTimes(array $data): array
    {
        return array_values(array_filter(
            array_map(static fn(array $goal) => $goal['time'] ?? null, $data['goals']),
            static fn($time) => $time !== null,
        ));
    }

    private static function gameplay(): array
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . '/api/v1/gameplay?game=700&token=' . self::TOKEN, false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');
        $payload = json_decode((string) $body, true);
        self::assertIsArray($payload, 'not JSON: ' . substr((string) $body, 0, 300));
        return $payload['data'];
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
