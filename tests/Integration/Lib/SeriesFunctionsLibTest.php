<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SeriesFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('series.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // Fixture constants: series_id=100 ('Open'), pool_id=200 ('Pool A'),
    // season='HRN2026', teams 300 ('Helsinki Heat') + 301 ('Tampere Tempest').
    // SeriesName/SeriesSeasonName use U_() → translation stack; those functions
    // are covered by integration tests that load the full app stack instead.

    public function testSeriesSeasonIdReturnsFixtureSeasonId(): void
    {
        $this->assertSame('HRN2026', SeriesSeasonId(100));
    }

    public function testSeriesSeasonIdReturnsNullForUnknownSeries(): void
    {
        $this->assertNull(SeriesSeasonId(99999));
    }

    public function testSeriesPoolsReturnsFixturePool(): void
    {
        $pools = SeriesPools(100);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['name']);
    }

    public function testSeriesPoolsOnlyVisibleFiltersCorrectly(): void
    {
        $all     = SeriesPools(100, false);
        $visible = SeriesPools(100, true);
        $this->assertCount(count($all), $visible);
    }

    public function testSeriesPoolsReturnsEmptyForUnknownSeries(): void
    {
        $pools = SeriesPools(99999);
        $this->assertIsArray($pools);
        $this->assertCount(0, $pools);
    }

    public function testSeriesTeamsReturnsFixtureTeams(): void
    {
        $teams = SeriesTeams(100);
        $this->assertCount(2, $teams);
        $names = array_column($teams, 'name');
        $this->assertContains('Helsinki Heat', $names);
        $this->assertContains('Tampere Tempest', $names);
    }

    public function testSeriesTeamsReturnsEmptyForUnknownSeries(): void
    {
        $teams = SeriesTeams(99999);
        $this->assertIsArray($teams);
        $this->assertCount(0, $teams);
    }

    public function testSeriesInfoReturnsFixtureData(): void
    {
        $info = SeriesInfo(100);
        $this->assertIsArray($info);
        $this->assertSame('Open', $info['name']);
        $this->assertSame('HRN2026', $info['season']);
    }
}
