<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class UrlFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('url.functions.php', 'database_only');
    }

    /** @var list<int> url_ids inserted during tests */
    private array $insertedUrlIds = [];

    protected function tearDown(): void
    {
        if (!empty($this->insertedUrlIds)) {
            $ids = implode(',', $this->insertedUrlIds);
            DBQuery("DELETE FROM uo_urls WHERE url_id IN ($ids)");
            $this->insertedUrlIds = [];
        }
        LegacyApp::closeDatabaseConnection();
    }

    public function testGetUrlByIdReturnsFixtureRow(): void
    {
        $url = GetUrlById(3); // season/menulink/Harness Event Site
        $this->assertSame('Harness Event Site', $url['name']);
        $this->assertSame('season', $url['owner']);
    }

    public function testGetUrlByIdReturnsFalseForMissingId(): void
    {
        $this->assertFalse((bool) GetUrlById(999999));
    }

    public function testGetUrlReturnsFixtureSeasonMenuLink(): void
    {
        $url = GetUrl('season', 'HRN2026', 'menulink');

        $this->assertSame('Harness Event Site', $url['name']);
        $this->assertSame('https://example.com/harness', $url['url']);
        $this->assertSame('0', (string) $url['ismedialink']);
    }

    public function testGetUrlListReturnsOnlyNonMediaLinksInStableOrder(): void
    {
        $urls = GetUrlList('season', 'HRN2026');

        $this->assertCount(2, $urls);
        $this->assertSame(['Harness Event Site', 'Harness Admin'], array_column($urls, 'name'));
        $this->assertSame(['menulink', 'menumail'], array_column($urls, 'type'));
    }

    public function testGetUrlListWithMedialinksReturnsEmptyForNoMediaInFixture(): void
    {
        $urls = GetUrlList('season', 'HRN2026', true);
        $this->assertSame([], $urls);
    }

    public function testGetUrlListByTypeArrayReturnsEmptyForEmptyTypeArray(): void
    {
        $this->assertSame([], GetUrlListByTypeArray([], 'HRN2026'));
    }

    public function testGetUrlListByTypeArrayReturnsMatchingRows(): void
    {
        // GetUrlListByTypeArray hardcodes owner='ultiorganizer', but the fixture's menulink
        // row has owner='season' — it never matches, regardless of type/ownerId.
        $rows = GetUrlListByTypeArray(['menulink'], 'HRN2026');
        $this->assertSame([], $rows);
    }

    public function testGetMediaUrlListReturnsEmptyForNoMediaLinks(): void
    {
        // Non-game owner branch
        $rows = GetMediaUrlList('season', 'HRN2026');
        $this->assertSame([], $rows);
    }

    public function testGetMediaUrlListGameOwnerBranchReturnsArray(): void
    {
        // Game owner — uses different JOIN query, but still requires ismedialink=1, and no
        // fixture uo_urls row has that flag set.
        $rows = GetMediaUrlList('game', '1');
        $this->assertSame([], $rows);
    }

    public function testGetMediaUrlListWithTypeFilterReturnsArray(): void
    {
        // No fixture uo_urls row has ismedialink=1, regardless of the type filter.
        $rows = GetMediaUrlList('season', 'HRN2026', 'image');
        $this->assertSame([], $rows);
    }

    public function testGetMediaUrlListForGamesReturnsEmptyForNoIds(): void
    {
        $this->assertSame([], GetMediaUrlListForGames([]));
    }

    public function testGetMediaUrlListForGamesReturnsArrayForValidIds(): void
    {
        // Fixture has no uo_urls rows with owner='game', so no game ever has media urls.
        $result = GetMediaUrlListForGames([1, 2]);
        $this->assertSame([], $result);
    }

    public function testGetMediaUrlListForGamesWithTypeFilterReturnsArray(): void
    {
        $result = GetMediaUrlListForGames([1], 'image');
        $this->assertSame([], $result);
    }

    public function testGetUrlTypesReturnsList(): void
    {
        $types = GetUrlTypes();
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
        $this->assertArrayHasKey('type', $types[0]);
        $this->assertArrayHasKey('icon', $types[0]);
    }

    public function testGetMediaUrlTypesReturnsList(): void
    {
        $types = GetMediaUrlTypes();
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
        $dbTypes = array_column($types, 'type');
        $this->assertContains('image', $dbTypes);
        $this->assertContains('video', $dbTypes);
    }

    // --- Admin-only write functions ---

    public function testAddUrlInsertsRowAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = AddUrl([
                'owner' => 'season',
                'owner_id' => 'HRN2026',
                'type' => 'homepage',
                'name' => 'Test Link',
                'url' => 'https://test.example.com',
                'ordering' => '9',
            ]);
            $this->assertNotFalse($result);
            $urlId = (int) DBQueryToValue("SELECT url_id FROM uo_urls WHERE name='Test Link' AND owner='season'");
            $this->assertGreaterThan(0, $urlId);
            $this->insertedUrlIds[] = $urlId;
        } finally {
            $_SESSION = [];
        }
    }

    public function testAddMailInsertsRowAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = AddMail([
                'owner' => 'season',
                'owner_id' => 'HRN2026',
                'type' => 'menumail',
                'name' => 'Test Mail',
                'url' => 'testmail@example.com',
                'ordering' => '9',
            ]);
            $this->assertNotFalse($result);
            $urlId = (int) DBQueryToValue("SELECT url_id FROM uo_urls WHERE name='Test Mail'");
            $this->insertedUrlIds[] = $urlId;
        } finally {
            $_SESSION = [];
        }
    }

    public function testSetUrlUpdatesRowAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $origName = DBQueryToValue("SELECT name FROM uo_urls WHERE url_id=3");
            SetUrl([
                'url_id' => 3,
                'owner' => 'season',
                'owner_id' => 'HRN2026',
                'type' => 'menulink',
                'name' => 'Updated Name',
                'url' => 'https://example.com/harness',
                'ordering' => '1',
            ]);
            $updated = DBQueryToValue("SELECT name FROM uo_urls WHERE url_id=3");
            $this->assertSame('Updated Name', $updated);
        } finally {
            // Restore original name
            DBQuery("UPDATE uo_urls SET name='" . DBEscapeString((string)$origName) . "' WHERE url_id=3");
            $_SESSION = [];
        }
    }

    public function testSetMailUpdatesRowAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $origName = DBQueryToValue("SELECT name FROM uo_urls WHERE url_id=4");
            SetMail([
                'url_id' => 4,
                'owner' => 'season',
                'owner_id' => 'HRN2026',
                'type' => 'menumail',
                'name' => 'Updated Mail',
                'url' => 'admin@example.com',
                'ordering' => '2',
            ]);
            $updated = DBQueryToValue("SELECT name FROM uo_urls WHERE url_id=4");
            $this->assertSame('Updated Mail', $updated);
        } finally {
            DBQuery("UPDATE uo_urls SET name='" . DBEscapeString((string)$origName) . "' WHERE url_id=4");
            $_SESSION = [];
        }
    }

    public function testRemoveUrlDeletesRowAsSuperAdmin(): void
    {
        // Insert a throwaway URL to remove
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $throwawayId = null;
        try {
            AddUrl([
                'owner' => 'season',
                'owner_id' => 'HRN2026',
                'type' => 'other',
                'name' => 'Throwaway',
                'url' => 'https://throwaway.example.com',
                'ordering' => '99',
            ]);
            $throwawayId = (int) DBQueryToValue("SELECT url_id FROM uo_urls WHERE name='Throwaway'");
            $this->assertGreaterThan(0, $throwawayId);
            $result = RemoveUrl($throwawayId);
            $this->assertNotFalse($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_urls WHERE url_id=$throwawayId");
            $this->assertSame('0', $count);
            $throwawayId = null;
        } finally {
            if ($throwawayId !== null) {
                $this->insertedUrlIds[] = $throwawayId;
            }
            $_SESSION = [];
        }
    }

    // --- AddMediaUrl / RemoveMediaUrl full path ---

    public function testAddMediaUrlInsertsMediaLinkAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $newId = (int) AddMediaUrl([
                'owner' => 'game',
                'owner_id' => '1',
                'type' => 'video',
                'name' => 'Test Video',
                'url' => 'https://video.example.com',
                'mediaowner' => 'admin',
            ]);
            $this->assertGreaterThan(0, $newId);
            $this->insertedUrlIds[] = $newId;
            $row = DBQueryToRow("SELECT ismedialink, type FROM uo_urls WHERE url_id=$newId");
            $this->assertSame('1', $row['ismedialink']);
            $this->assertSame('video', $row['type']);
        } finally {
            $_SESSION = [];
        }
    }

    public function testGetMediaUrlListForGamesGroupsByGameId(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $mediaId = null;
        try {
            $mediaId = (int) AddMediaUrl([
                'owner' => 'game',
                'owner_id' => '1',
                'type' => 'image',
                'name' => 'Test Image',
                'url' => 'https://img.example.com',
                'mediaowner' => 'admin',
            ]);
            $this->insertedUrlIds[] = $mediaId;
            $result = GetMediaUrlListForGames([1]);
            $this->assertArrayHasKey('1', $result);
            $this->assertNotEmpty($result['1']);
        } finally {
            $_SESSION = [];
        }
    }

    public function testAddAndRemoveMediaUrlFullPath(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $mediaId = null;
        try {
            $mediaId = (int) AddMediaUrl([
                'owner' => 'game',
                'owner_id' => '2',
                'type' => 'live',
                'name' => 'Live Stream',
                'url' => 'https://live.example.com',
                'mediaowner' => 'admin',
            ]);
            $this->assertGreaterThan(0, $mediaId);
            $result = RemoveMediaUrl($mediaId);
            $this->assertNotFalse($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_urls WHERE url_id=$mediaId");
            $this->assertSame('0', $count);
            $mediaId = null;
        } finally {
            if ($mediaId !== null) {
                $this->insertedUrlIds[] = $mediaId;
            }
            $_SESSION = [];
        }
    }

    // --- RemoveMediaUrl early-return path ---

    public function testRemoveMediaUrlReturnsFalseForNonExistentId(): void
    {
        $result = RemoveMediaUrl(999999);
        $this->assertFalse($result);
    }

    public function testRemoveMediaUrlReturnsFalseForNonMediaUrl(): void
    {
        $result = RemoveMediaUrl(3); // fixture ID 3 is not a media link (ismedialink=0)
        $this->assertFalse($result);
    }

    // --- CurrentUserDatabaseId ---

    public function testCurrentUserDatabaseIdReturnsAdminId(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $id = CurrentUserDatabaseId();
            $this->assertGreaterThan(0, $id);
        } finally {
            $_SESSION = [];
        }
    }

    // --- CanRemoveMediaUrl / CanEditMediaTarget (superadmin path) ---

    public function testCanRemoveMediaUrlReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $fakeUrl = ['publisher_id' => 0, 'ismedialink' => '1'];
            $this->assertTrue(CanRemoveMediaUrl($fakeUrl));
        } finally {
            $_SESSION = [];
        }
    }

    public function testCanEditMediaTargetReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertTrue(CanEditMediaTarget('season', 'HRN2026'));
        } finally {
            $_SESSION = [];
        }
    }

    public function testCanEditMediaTargetReturnsFalseForNonAdminWithNoMediaRight(): void
    {
        LegacyApp::loadUserFunctions();
        // No loginAsAdmin — uid is '' → not superadmin → hasAddMediaRight returns false
        $this->assertFalse(CanEditMediaTarget('game', '1'));
    }

    public function testCanEditMediaTargetReturnsFalseForZeroOwnerId(): void
    {
        LegacyApp::loadUserFunctions();
        // uid set → hasAddMediaRight true; ownerId=0 → return false path.
        $_SESSION['uid'] = 'testuser';
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        try {
            $this->assertFalse(CanEditMediaTarget('game', '0'));
        } finally {
            unset($_SESSION['uid']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
        }
    }

    public function testCanEditMediaTargetCoversSwitchCasesWithSeriesAdminRole(): void
    {
        LegacyApp::loadUserFunctions();
        // Use seriesadmin[100] (not superadmin) to exercise the switch body for multiple owners.
        $_SESSION['uid'] = 'testuser';
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        try {
            // 'series' → hasEditGamesRight(100) → true
            $this->assertTrue(CanEditMediaTarget('series', '100'));
            // 'team'   → hasEditPlayersRight(300) → true (team 300 ∈ series 100)
            $this->assertTrue(CanEditMediaTarget('team', '300'));
            // 'game'   → hasEditGameEventsRight(700) → true (game 700 ∈ series 100)
            $this->assertTrue(CanEditMediaTarget('game', '700'));
            // 'country' → always false
            $this->assertFalse(CanEditMediaTarget('country', '1'));
            // unknown type → falls to return false after switch
            $this->assertFalse(CanEditMediaTarget('unknown_type', '1'));
        } finally {
            unset($_SESSION['userproperties']['userrole']['seriesadmin'][100], $_SESSION['uid']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
        }
    }

    public function testCanRemoveMediaUrlReturnsFalseForNonAdminWithoutPublisherMatch(): void
    {
        LegacyApp::loadUserFunctions();
        // No loginAsAdmin — not superadmin; hasAddMediaRight('') returns false
        $fakeUrl = ['publisher_id' => 0, 'ismedialink' => '1'];
        $this->assertFalse(CanRemoveMediaUrl($fakeUrl));
    }

    // --- CanEditClubMediaTarget ---

    public function testCanEditClubMediaTargetReturnsFalseForClubWithNoTeams(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        // No teams in fixture have club=9999
        $this->assertFalse(CanEditClubMediaTarget(9999));
    }

    public function testCanEditClubMediaTargetReturnsTrueForClubWithTeamAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        // Create club 9999 (fk_team_club requires a real parent row) and
        // temporarily associate team 300 with it.
        DBQuery("INSERT INTO uo_club (club_id, name) VALUES (9999, 'Harness Test Club')");
        DBQuery("UPDATE uo_team SET club=9999 WHERE team_id=300");
        try {
            $this->assertTrue(CanEditClubMediaTarget(9999));
        } finally {
            DBQuery("UPDATE uo_team SET club=NULL WHERE team_id=300");
            DBQuery("DELETE FROM uo_club WHERE club_id=9999");
        }
    }

    // --- CanEditPlayerMediaTarget ---

    public function testCanEditPlayerMediaTargetReturnsFalseForProfileWithNoPlayer(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        // No player with profile_id=999999 in fixture
        $this->assertFalse(CanEditPlayerMediaTarget(999999));
    }

    public function testCanEditPlayerMediaTargetReturnsTrueWhenPlayerAdminForProfile(): void
    {
        LegacyApp::loadUserFunctions();
        // Set playeradmin role for profile 42
        $_SESSION['userproperties']['userrole']['playeradmin'][42] = 1;
        try {
            $this->assertTrue(CanEditPlayerMediaTarget(42));
        } finally {
            unset($_SESSION['userproperties']['userrole']['playeradmin'][42]);
        }
    }
}
