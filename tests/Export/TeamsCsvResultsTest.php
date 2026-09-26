<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * ext/teamscsv.php and ext/poolscsv.php both report each team's games, wins
 * and goals. TeamsToCsv() aggregates only games whose spirit is visible
 * (g.show_spirit=1), a filter that belongs to its SpiritPoints column, so an
 * event that does not publish spirit (the fixture) exports every team with no
 * games at all. It also counts wins by score only, while docs/ranking.md
 * records a forfeit at 0-0 and lets uo_game.forfeit award the win. The pool
 * CSV, which reads the same results through TeamStatsByPool(), is the
 * reference.
 */
final class TeamsCsvResultsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_game SET homescore=15, visitorscore=11, forfeit=0 WHERE game_id=700");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPoolCsvReportsFixtureGame700(): void
    {
        $rows = self::csv('/ext/poolscsv.php?season=HRN2026');

        $this->assertSame(['1', '1', '15', '11'], self::pick($rows['Helsinki Heat'], ['Games', 'Wins', 'GoalsFor', 'GoalsAgainst']));
        $this->assertSame(['1', '0', '11', '15'], self::pick($rows['Tampere Tempest'], ['Games', 'Wins', 'GoalsFor', 'GoalsAgainst']));
    }

    public function testTeamCsvReportsTheSameResults(): void
    {
        $rows = self::csv('/ext/teamscsv.php?season=HRN2026');

        $this->assertSame(['1', '1', '15', '11'], self::pick($rows['Helsinki Heat'], ['Games', 'Wins', 'GoalsFor', 'GoalsAgainst']));
        $this->assertSame(['1', '0', '11', '15'], self::pick($rows['Tampere Tempest'], ['Games', 'Wins', 'GoalsFor', 'GoalsAgainst']));
    }

    public function testTeamCsvCountsAForfeitWinLikeThePoolCsv(): void
    {
        // The away team forfeits game 700 at 0-0: Helsinki Heat wins.
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=0, forfeit=2 WHERE game_id=700");

        $this->assertSame('1', self::csv('/ext/poolscsv.php?season=HRN2026')['Helsinki Heat']['Wins']);
        $this->assertSame('1', self::csv('/ext/teamscsv.php?season=HRN2026')['Helsinki Heat']['Wins']);
    }

    /** @return array<string, array<string, string>> rows keyed by team name */
    private static function csv(string $path): array
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = (string) file_get_contents($baseUrl . $path, false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');

        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body))));
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $rows = [];
        foreach ($lines as $line) {
            $row = array_combine($header, str_getcsv($line, ',', '"', '\\'));
            $rows[$row['Team']] = $row;
        }
        return $rows;
    }

    /** @return list<string> */
    private static function pick(array $row, array $keys): array
    {
        return array_map(static fn(string $key): string => $row[$key], $keys);
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
