<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Anonymous access to an event that is in maintenance.
 *
 * EnforceSoftMaintenanceForView() asks MaintenanceSeasonFromView() for the
 * event behind the request, and that helper returns the event of the first id
 * parameter it finds, checking `season` before `team`. The test puts the
 * fixture event in maintenance and seeds a second public event that is not, to
 * name in the extra parameter. Assertions are status codes only.
 */
final class EventMaintenanceBypassTest extends TestCase
{
    private const OPEN_SEASON = 'HRNOPEN';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', 'Open Cup', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0, 1, 'Europe/Helsinki', 1003)",
            self::OPEN_SEASON,
        ));
        DBQuery("UPDATE uo_season SET maintenance_mode=1 WHERE season_id='HRN2026'");
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET maintenance_mode=0 WHERE season_id='HRN2026'");
        self::cleanUp();
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testTeamCardOfAnEventInMaintenanceIsRefused(): void
    {
        // Precondition: the gate recognises the fixture event as in maintenance.
        $this->assertSame(503, self::httpStatus('/index.php?view=teamcard&team=300'));
    }

    public function testOpenEventStillRenders(): void
    {
        // Contrast: the event named in the extra parameter is itself reachable.
        $this->assertSame(200, self::httpStatus('/index.php?view=games&season=' . self::OPEN_SEASON));
    }

    public function testNamingAnotherEventDoesNotOpenATeamCardOfAnEventInMaintenance(): void
    {
        $this->assertSame(503, self::httpStatus('/index.php?view=teamcard&team=300&season=' . self::OPEN_SEASON));
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::OPEN_SEASON));
    }

    private static function httpStatus(string $path): int
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20],
        ]);
        file_get_contents($baseUrl . $path, false, $context);
        preg_match('/\s(\d{3})\b/', $http_response_header[0] ?? '', $m);
        return (int) ($m[1] ?? 0);
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
