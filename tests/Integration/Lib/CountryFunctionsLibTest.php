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
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
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

    public function testCountryInfoReturnsEmptyArrayForMissingId(): void
    {
        $this->assertSame([], CountryInfo(99999));
    }

    public function testCountryNameReturnsNameForFixtureCountry(): void
    {
        $name = CountryName(1064);
        $this->assertSame('Finland', $name);
    }

    public function testCountryNameReturnsEmptyStringForMissingId(): void
    {
        $this->assertSame('', CountryName(99999));
    }

    public function testCountryIdReturnsIdForKnownName(): void
    {
        $id = CountryId('Finland');
        $this->assertSame('1064', (string) $id);
    }

    public function testCountryListReturnsValidCountries(): void
    {
        $list = CountryList(true, false);
        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
        $ids = array_column($list, 'country_id');
        $this->assertContains('1064', $ids);
    }

    public function testCountryListWithOnlyPlayedFilter(): void
    {
        // Only Finland (1064) has any fixture teams, so it's the sole "played" country.
        $list = CountryList(false, true);
        $this->assertCount(1, $list);
        $this->assertSame('1064', $list[0]['country_id']);
        $this->assertSame('Finland', $list[0]['name']);
    }

    public function testCountryListWithBothFilters(): void
    {
        // Finland is also valid=1, so combining both filters gives the same single result.
        $list = CountryList(true, true);
        $this->assertCount(1, $list);
        $this->assertSame('1064', $list[0]['country_id']);
    }

    public function testCountryListWithNoFilters(): void
    {
        $list = CountryList(false, false);
        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
    }

    public function testHasPlayableCountriesReturnsBoolBasedOnFixture(): void
    {
        // Finland (1064, valid=1) has fixture teams, so at least one playable country exists.
        $result = HasPlayableCountries();
        $this->assertTrue($result);
    }

    public function testCountryDropListReturnsHtmlSelect(): void
    {
        $html = CountryDropList('country_id', 'country');
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('</select>', $html);
    }

    public function testCountryDropListWithValuesSelectsMatchingOption(): void
    {
        $html = CountryDropListWithValues('cid', 'cname', 1064);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('selected=', $html);
    }

    public function testCountryDropListWithValuesWithWidthStyle(): void
    {
        $html = CountryDropListWithValues('cid', 'cname', 1064, '200px');
        $this->assertStringContainsString('style=', $html);
    }

    public function testCountryNumOfTeamsReturnsCountForFixtureCountry(): void
    {
        $count = (int) CountryNumOfTeams(1064);
        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function testCountryTeamsReadsFixtureTeamsForSeason(): void
    {
        $teams = CountryTeams(1064, 'HRN2026');

        $this->assertCount(2, $teams);
        $this->assertSame('Helsinki Heat', $teams[0]['name']);
        $this->assertSame('Tampere Tempest', $teams[1]['name']);
    }

    public function testCountryTeamsWithNoSeasonReturnsAllTeams(): void
    {
        // No-season branch requires a matching uo_team_stats row; both fixture teams have one.
        $teams = CountryTeams(1064);
        $this->assertCount(2, $teams);
        $this->assertSame(['Helsinki Heat', 'Tampere Tempest'], array_column($teams, 'name'));
    }

    public function testCanDeleteCountryReturnsFalseWhenTeamsExist(): void
    {
        $this->assertFalse(CanDeleteCountry(1064));
    }

    public function testCanDeleteCountryReturnsTrueForNewCountry(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $newId = (int) AddCountry('Test Country XYZ', 'TCX', '');
            $this->assertTrue(CanDeleteCountry($newId));
        } finally {
            if ($newId) {
                DBQuery("DELETE FROM uo_country WHERE country_id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testAddCountryInsertsRowAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $newId = (int) AddCountry('Test Nation', 'TSN', '');
            $this->assertGreaterThan(0, $newId);
            $name = DBQueryToValue("SELECT name FROM uo_country WHERE country_id=$newId");
            $this->assertSame('Test Nation', $name);
        } finally {
            if ($newId) {
                DBQuery("DELETE FROM uo_country WHERE country_id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testSetCountryValidityUpdatesValidFlag(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $newId = (int) AddCountry('Valid Test Country', 'VTC', '');
            SetCountryValidity($newId, 0);
            $valid = DBQueryToValue("SELECT valid FROM uo_country WHERE country_id=$newId");
            $this->assertSame('0', $valid);
            SetCountryValidity($newId, 1);
        } finally {
            if ($newId) {
                DBQuery("DELETE FROM uo_country WHERE country_id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testRemoveCountryDeletesUnusedCountry(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loadLibFilesUsingProfile(['logging.functions.php'], 'database_only');
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $newId = (int) AddCountry('Remove Test Country', 'RTC', '');
            $result = RemoveCountry($newId);
            $this->assertNotFalse($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_country WHERE country_id=$newId");
            $this->assertSame('0', $count);
            $newId = null;
        } finally {
            if ($newId) {
                DBQuery("DELETE FROM uo_country WHERE country_id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testCountryPoolsReturnsPoolsForSeason(): void
    {
        // The query LEFT JOINs uo_team_pool -> uo_team without dedup, so pool 200 appears once
        // per matching team row (300 and 301, both country 1064) - 2 rows, same pool.
        $pools = CountryPools('HRN2026', 1064);
        $this->assertCount(2, $pools);
        $this->assertSame(['200', '200'], array_column($pools, 'pool_id'));
    }

    public function testGetTimeZoneArrayReturnsNonEmptyList(): void
    {
        $tz = GetTimeZoneArray();
        $this->assertIsArray($tz);
        $this->assertNotEmpty($tz);
        $this->assertContains('UTC', $tz);
        $this->assertContains('Europe/Helsinki', $tz);
    }
}
