<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CountryFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadCountryFunctions();
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testCountryNameReadsSeededReferenceData(): void
    {
        $this->assertSame('Finland', CountryName(1064));
    }

    public function testCountryListOnlyPlayedReturnsCountriesFromFixtureTeams(): void
    {
        $countries = CountryList(true, true);

        $this->assertNotEmpty($countries);
        $this->assertSame(1064, (int) $countries[0]['country_id']);
        $this->assertSame('Finland', $countries[0]['name']);
    }
}

