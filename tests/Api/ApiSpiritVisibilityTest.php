<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Spirit scores in /api/v1/gameplay follow the event's visibility rules.
 *
 * gameplay.php and the spirit CSV export (#92) only show spirit points when
 * CanViewSpiritScoresForGame() allows it: the event publishes spirit points
 * (showspiritpoints) and the game's show_spirit flag is set. The fixture event
 * hides them (showspiritpoints=0). The test seeds one team's spirit scores for
 * game 700 and compares the API with the rule in both directions.
 */
final class ApiSpiritVisibilityTest extends TestCase
{
    private const TOKEN = 'harness-api-token';
    private const GAME = 700;
    private const TEAM = 300;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        foreach ([1009, 1010, 1011, 1012, 1013] as $categoryId) {
            DBQuery(sprintf(
                "INSERT INTO uo_spirit_score (game_id, team_id, category_id, value) VALUES (%d, %d, %d, 3)",
                self::GAME,
                self::TEAM,
                $categoryId,
            ));
        }
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
        DBQuery(sprintf("UPDATE uo_game SET show_spirit=0 WHERE game_id=%d", self::GAME));
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPublishedSpiritScoresAreReturned(): void
    {
        // Contrast: when the event publishes spirit points, the seeded scores come back.
        DBQuery("UPDATE uo_season SET showspiritpoints=1 WHERE season_id='HRN2026'");
        DBQuery(sprintf("UPDATE uo_game SET show_spirit=1 WHERE game_id=%d", self::GAME));

        $this->assertSame([3, 3, 3, 3, 3], self::exposedSpiritValues());
    }

    public function testHiddenSpiritScoresAreNotReturned(): void
    {
        // Fixture state: the event does not publish spirit points.
        $this->assertSame([], self::exposedSpiritValues());
    }

    /** @return list<int|float> every spirit value the endpoint reveals */
    private static function exposedSpiritValues(): array
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents(
            $baseUrl . '/api/v1/gameplay?game=' . self::GAME . '&token=' . self::TOKEN,
            false,
            $context,
        );
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');
        $payload = json_decode((string) $body, true);
        self::assertIsArray($payload, 'not JSON: ' . substr((string) $body, 0, 300));

        $spirit = $payload['data']['spirit'] ?? null;
        $values = [];
        if (is_array($spirit)) {
            foreach ($spirit['categories'] ?? [] as $category) {
                foreach (['home', 'away'] as $side) {
                    if ($category[$side] !== null) {
                        $values[] = $category[$side];
                    }
                }
            }
        }
        return $values;
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_spirit_score WHERE game_id=%d", self::GAME));
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
