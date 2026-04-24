<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SeasonFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('season.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testSeasonSeriesAndPoolsReadBaselineFixture(): void
    {
        $series = SeasonSeries('HRN2026', true);
        $pools = SeasonPools('HRN2026', true, true);

        $this->assertCount(1, $series);
        $this->assertSame('Open', $series[0]['name']);
        $this->assertCount(1, $pools);
        $this->assertSame('Pool A', $pools[0]['poolname']);
    }

    public function testSeasonReservationsAndMissingProfilesReadFixtureState(): void
    {
        $reservations = SeasonReservations('HRN2026');

        $this->assertCount(2, $reservations);
        $this->assertSame('Harness Field Complex', $reservations[0]['name']);
        $this->assertSame(4, (int) SeasonMissingPlayerProfilesCount('HRN2026'));
    }
}
