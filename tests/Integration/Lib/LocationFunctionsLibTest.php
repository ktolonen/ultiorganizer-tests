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
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
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

    // --- GetSearchLocations branches ---

    public function testGetSearchLocationsWithNoFilterReturnsAllLocations(): void
    {
        $result = DBFetchAllAssoc(GetSearchLocations());
        $ids = array_column($result, 'id');
        $this->assertContains('400', $ids);
    }

    public function testGetSearchLocationsWithSearchParamFilters(): void
    {
        $_GET['search'] = 'Harness';
        $result = DBFetchAllAssoc(GetSearchLocations());
        $this->assertNotEmpty($result);
        $this->assertSame('Harness Field Complex', $result[0]['name']);
    }

    public function testGetSearchLocationsWithQueryParamFilters(): void
    {
        $_GET['query'] = 'Harness';
        $result = DBFetchAllAssoc(GetSearchLocations());
        $this->assertNotEmpty($result);
    }

    public function testGetSearchLocationsWithQParamFilters(): void
    {
        $_GET['q'] = 'Harness';
        $result = DBFetchAllAssoc(GetSearchLocations());
        $this->assertNotEmpty($result);
    }

    public function testGetSearchLocationsWithIdParamReturnsSpecificLocation(): void
    {
        $_GET['id'] = '400';
        $result = DBFetchAllAssoc(GetSearchLocations());
        $this->assertNotEmpty($result);
        $this->assertSame('400', $result[0]['id']);
    }

    // --- GetSearchLocationsArray ---

    public function testGetSearchLocationsArrayWithLocalizedInfoReturnsRows(): void
    {
        $locations = GetSearchLocationsArray(true);
        $this->assertIsArray($locations);
        $this->assertNotEmpty($locations);
    }

    public function testGetSearchLocationsArrayWithoutLocalizedInfoDeduplicates(): void
    {
        $locations = GetSearchLocationsArray(false);
        $this->assertIsArray($locations);
        // All entries should have sequential numeric keys (array_values result)
        $this->assertArrayHasKey(0, $locations);
    }

    // --- LocationInfo ---

    public function testLocationInfoReturnsFixtureLocation(): void
    {
        $info = LocationInfo(400);
        $this->assertIsArray($info);
        $this->assertSame('400', $info['id']);
        $this->assertSame('Harness Field Complex', $info['name']);
    }

    public function testLocationInfoReturnsNullForMissingId(): void
    {
        $info = LocationInfo(99999);
        $this->assertFalse((bool) $info);
    }

    // --- LocationInfoText ---

    public function testLocationInfoTextReturnsNullForMissingLocale(): void
    {
        $text = LocationInfoText(400, 'xx_XX');
        $this->assertNull($text);
    }

    // --- CanDeleteLocation ---

    public function testCanDeleteLocationReturnsTrueWhenNoReservations(): void
    {
        $id = DBQueryInsert("INSERT INTO uo_location (name, address, fields, indoor, lat, lng) VALUES ('Temp Delete Test', '', 1, 0, 0, 0)");
        try {
            $this->assertTrue(CanDeleteLocation($id));
        } finally {
            DBQuery("DELETE FROM uo_location WHERE id=$id");
        }
    }

    // --- AddLocation / updateInfos / SetLocation / RemoveLocation ---

    public function testAddLocationInsertsNewLocationAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $newId = AddLocation('Test Venue', '123 Test St', [], 2, 0, '60.1', '24.9', 'HRN2026');
            $this->assertIsInt($newId);
            $this->assertGreaterThan(0, $newId);
            $name = DBQueryToValue("SELECT name FROM uo_location WHERE id=$newId");
            $this->assertSame('Test Venue', $name);
        } finally {
            if ($newId !== null) {
                DBQuery("DELETE FROM uo_location WHERE id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testAddLocationWithInfoCallsUpdateInfos(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newId = null;
        try {
            $info = ['en_GB.utf8' => 'Test info text'];
            $newId = AddLocation('Info Venue', '', $info, 1, 0, '0', '0', 'HRN2026');
            $this->assertGreaterThan(0, $newId);
            $stored = DBQueryToValue("SELECT info FROM uo_location_info WHERE location_id=$newId AND locale='en_GB.utf8'");
            $this->assertSame('Test info text', $stored);
        } finally {
            if ($newId !== null) {
                DBQuery("DELETE FROM uo_location_info WHERE location_id=$newId");
                DBQuery("DELETE FROM uo_location WHERE id=$newId");
            }
            $_SESSION = [];
        }
    }

    public function testSetLocationUpdatesExistingLocationAsSuperAdmin(): void
    {
        // Insert a throwaway location to avoid mutating fixture location 400
        $id = DBQueryInsert("INSERT INTO uo_location (name, address, fields, indoor, lat, lng) VALUES ('SetTest Venue', 'Old Address', 1, 0, 0, 0)");
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            SetLocation($id, 'Updated Venue', 'New Address', [], 2, 1, '61.0', '25.0', 'HRN2026');
            $row = DBFetchAllAssoc(DBQuery("SELECT name, address, fields, indoor FROM uo_location WHERE id=$id"));
            $this->assertSame('Updated Venue', $row[0]['name']);
            $this->assertSame('New Address', $row[0]['address']);
        } finally {
            DBQuery("DELETE FROM uo_location WHERE id=$id");
            $_SESSION = [];
        }
    }

    public function testUpdateInfosWithEmptyInfoStringDeletesExistingRow(): void
    {
        // Insert a location with info, then call updateInfos with empty string to trigger DELETE path
        $id = DBQueryInsert("INSERT INTO uo_location (name, address, fields, indoor, lat, lng) VALUES ('UpdateInfos Test', '', 1, 0, 0, 0)");
        try {
            DBQuery("INSERT INTO uo_location_info (location_id, locale, info) VALUES ($id, 'en_GB.utf8', 'some info')");
            $before = DBQueryToValue("SELECT COUNT(*) FROM uo_location_info WHERE location_id=$id");
            $this->assertSame('1', $before);

            updateInfos($id, ['en_GB.utf8' => '']);

            $after = DBQueryToValue("SELECT COUNT(*) FROM uo_location_info WHERE location_id=$id");
            $this->assertSame('0', $after);
        } finally {
            DBQuery("DELETE FROM uo_location_info WHERE location_id=$id");
            DBQuery("DELETE FROM uo_location WHERE id=$id");
        }
    }

    public function testRemoveLocationDeletesUnusedLocationAsSuperAdmin(): void
    {
        $id = DBQueryInsert("INSERT INTO uo_location (name, address, fields, indoor, lat, lng) VALUES ('Remove Test', '', 1, 0, 0, 0)");
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = RemoveLocation($id);
            $this->assertTrue($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_location WHERE id=$id");
            $this->assertSame('0', $count);
        } finally {
            DBQuery("DELETE FROM uo_location WHERE id=$id");
            $_SESSION = [];
        }
    }
}
