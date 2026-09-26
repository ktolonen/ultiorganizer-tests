<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * statistics.php?list=spiritstandings ranks teams by their archived spirit
 * averages, event by event. teams.php?list=byspirit and the other spirit views
 * show spirit only where ShowSpiritScoresForSeason() allows it; the fixture
 * event does not publish spirit points. The asserted team name is fixture data,
 * so the test is locale-independent.
 */
final class StatisticsSpiritStandingsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        DBQuery("DELETE FROM uo_team_spirit_stats WHERE team_id=300");
        DBQuery("INSERT INTO uo_team_spirit_stats (team_id, season, series, category_id, games, average)
            VALUES (300, 'HRN2026', 100, 1009, 1, 3.5)");
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_team_spirit_stats WHERE team_id=300");
        DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPublishedSpiritIsRanked(): void
    {
        DBQuery("UPDATE uo_season SET showspiritpoints=1 WHERE season_id='HRN2026'");

        $this->assertStringContainsString('Helsinki Heat', self::spiritStandings());
    }

    public function testUnpublishedSpiritIsNotRanked(): void
    {
        $this->assertStringNotContainsString('Helsinki Heat', self::spiritStandings());
    }

    private static function spiritStandings(): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . '/index.php?view=statistics&list=spiritstandings', false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');
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
