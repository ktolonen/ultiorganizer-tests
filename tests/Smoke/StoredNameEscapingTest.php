<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Event, division, pool and reservation-group names on public HTML pages.
 *
 * These names are typed in by event admins. Most pages print them through
 * utf8entities(); the pages listed below print them raw into the markup (a
 * heading, the <title> element, an HTML comment, an <object> URL). The test
 * appends a harmless element to each name and asserts that every page shows
 * the name, but never the element as markup.
 *
 * Fixture ids only, and only the injected marker is asserted, so the test is
 * locale-independent.
 */
final class StoredNameEscapingTest extends TestCase
{
    private const MARKER = '<x-harness-mark>';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        $m = DBEscapeString(self::MARKER);
        DBQuery("UPDATE uo_season SET name=CONCAT(name, '$m'), showspiritpoints=1 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_series SET name=CONCAT(name, '$m') WHERE series_id=100");
        DBQuery("UPDATE uo_pool SET name=CONCAT(name, '$m') WHERE pool_id=200");
        DBQuery("UPDATE uo_reservation SET reservationgroup=CONCAT(reservationgroup, '$m') WHERE id IN (500, 501)");
        DBQuery("INSERT INTO uo_club (club_id, name, valid) VALUES (952, 'Harness Club', 1)");
        DBQuery("UPDATE uo_team SET club=952 WHERE team_id=300");
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET name='Harness Invitational 2026', showspiritpoints=0 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_series SET name='Open' WHERE series_id=100");
        DBQuery("UPDATE uo_pool SET name='Pool A' WHERE pool_id=200");
        DBQuery("UPDATE uo_reservation SET reservationgroup='Harness Invitational 2026' WHERE id IN (500, 501)");
        DBQuery("UPDATE uo_team SET club=NULL WHERE team_id=300");
        DBQuery("DELETE FROM uo_club WHERE club_id=952");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            // Contrast: pages that escape the same names.
            'pool status by pool (escaped)' => ['/index.php?view=poolstatus&pool=200'],
            'teams list (escaped)' => ['/index.php?view=teams&season=HRN2026'],
            // Event name in a heading.
            'team card' => ['/index.php?view=teamcard&team=300'],
            'club card' => ['/index.php?view=clubcard&club=952'],
            'country card' => ['/index.php?view=countrycard&country=1064'],
            // Division or event name in <title>.
            'pool status by division' => ['/index.php?view=poolstatus&series=100'],
            'division statistics' => ['/index.php?view=seriesstatus&series=100'],
            'spirit status' => ['/index.php?view=spiritstatus&series=100'],
            // Reservation group in an HTML comment.
            'schedule' => ['/index.php?view=games&season=HRN2026'],
            'played games' => ['/index.php?view=played&season=HRN2026'],
            'timetables' => ['/index.php?view=timetables&season=HRN2026'],
            // External helper pages.
            'ext export' => ['/ext/export.php?season=HRN2026'],
            'ext widget builder' => ['/ext/index.php?season=HRN2026'],
        ];
    }

    #[DataProvider('pages')]
    public function testNamesAreEscaped(string $path): void
    {
        $body = self::get($path);

        $this->assertStringContainsString('x-harness-mark', $body, 'the page shows one of the names');
        $this->assertStringNotContainsString(self::MARKER, $body);
    }

    private static function get(string $path): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        // Redirects are followed; the last status line is the final response's.
        $statusLines = array_values(array_filter(
            $http_response_header ?? [],
            static fn(string $line): bool => str_starts_with($line, 'HTTP/'),
        ));
        self::assertStringContainsString(' 200 ', (string) end($statusLines), $path);
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
