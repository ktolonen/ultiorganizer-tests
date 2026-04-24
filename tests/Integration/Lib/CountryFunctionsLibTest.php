<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CountryFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('country.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testCountryInfoReturnsFixtureMetadata(): void
    {
        $country = CountryInfo(1064);

        $this->assertSame(1064, (int) $country['country_id']);
        $this->assertSame('Finland', $country['name']);
        $this->assertSame('FIN', $country['abbreviation']);
    }

    public function testCountryTeamsReadsFixtureTeamsForSeason(): void
    {
        $teams = CountryTeams(1064, 'HRN2026');

        $this->assertCount(2, $teams);
        $this->assertSame('Helsinki Heat', $teams[0]['name']);
        $this->assertSame('Tampere Tempest', $teams[1]['name']);
    }
}
