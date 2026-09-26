<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * The SpiritPoints column of ext/teamscsv.php.
 *
 * ext/spiritcsv.php refuses to export anything unless
 * ShowSpiritScoresForSeason() allows it (the event publishes spirit points).
 * TeamsToCsv() sums spirit points for every game whose show_spirit flag is set,
 * without that event-level check. The test seeds both teams' spirit scores for
 * game 700, marks the game's spirit visible, and toggles the event flag.
 */
final class TeamsCsvSpiritTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        DBQuery("DELETE FROM uo_spirit_score WHERE game_id=700");
        foreach ([300, 301] as $teamId) {
            foreach ([1009, 1010, 1011, 1012, 1013] as $categoryId) {
                DBQuery(sprintf(
                    "INSERT INTO uo_spirit_score (game_id, team_id, category_id, value) VALUES (700, %d, %d, 2)",
                    $teamId,
                    $categoryId,
                ));
            }
        }
        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_spirit_score WHERE game_id=700");
        DBQuery("UPDATE uo_game SET show_spirit=0 WHERE game_id=700");
        DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPublishedSpiritPointsAreExported(): void
    {
        DBQuery("UPDATE uo_season SET showspiritpoints=1 WHERE season_id='HRN2026'");

        $this->assertSame(['10', '10'], self::spiritPoints());
    }

    public function testUnpublishedSpiritPointsAreNotExported(): void
    {
        // Reference: the spirit CSV refuses the same event.
        $this->assertStringNotContainsString('Helsinki Heat', self::get('/ext/spiritcsv.php?season=HRN2026'));

        $this->assertSame(['0', '0'], self::spiritPoints());
    }

    /** @return list<string> SpiritPoints of Helsinki Heat and Tampere Tempest */
    private static function spiritPoints(): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim(self::get('/ext/teamscsv.php?season=HRN2026')))));
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $points = [];
        foreach ($lines as $line) {
            $row = array_combine($header, str_getcsv($line, ',', '"', '\\'));
            $points[$row['Team']] = $row['SpiritPoints'];
        }
        return [$points['Helsinki Heat'], $points['Tampere Tempest']];
    }

    private static function get(string $path): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        return (string) file_get_contents($baseUrl . $path, false, $context);
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
