<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * A team's games in a hidden pool on the two public pages that list them.
 *
 * games.php passes $onlypublic to TimetableGames(), which gates games on their
 * pool's (playoff root's) visible flag (#84); teamcard.php lists the same
 * team's games without it. The test hides the fixture's only pool and compares
 * both pages. The asserted opponent name is fixture data, so the test is
 * locale-independent.
 */
final class HiddenPoolTeamCardTest extends TestCase
{
    private const POOL = 200;
    private const OPPONENT = 'Tampere Tempest';

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

    public function testVisiblePoolGamesAreOnBothPages(): void
    {
        $this->assertStringContainsString(self::OPPONENT, self::get('/index.php?view=games&team=300'));
        $this->assertStringContainsString(self::OPPONENT, self::get('/index.php?view=teamcard&team=300'));
    }

    public function testTeamScheduleLeavesOutHiddenPoolGames(): void
    {
        // Reference behaviour.
        DBQuery(sprintf("UPDATE uo_pool SET visible=0 WHERE pool_id=%d", self::POOL));

        $this->assertStringNotContainsString(self::OPPONENT, self::get('/index.php?view=games&team=300'));
    }

    public function testTeamCardLeavesOutHiddenPoolGames(): void
    {
        DBQuery(sprintf("UPDATE uo_pool SET visible=0 WHERE pool_id=%d", self::POOL));

        $this->assertStringNotContainsString(self::OPPONENT, self::get('/index.php?view=teamcard&team=300'));
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
