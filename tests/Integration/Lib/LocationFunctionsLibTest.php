<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class LocationFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('location.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testGetLocationsReturnsFixtureVenue(): void
    {
        $locations = GetLocations();

        $this->assertCount(1, $locations);
        $this->assertSame(400, (int) $locations[0]['id']);
        $this->assertSame('Harness Field Complex', $locations[0]['name']);
    }

    public function testCanDeleteLocationDetectsFixtureReservationUsage(): void
    {
        $this->assertFalse(CanDeleteLocation(400));
    }
}
