<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ClubFunctionsLibTest extends TestCase
{
    // Fixture constants: season='HRN2026', series=100, teams 300+301 (club=NULL).
    // No clubs are in the baseline fixture, so each test inserts its own.

    /** @var int[] club IDs created during a test, deleted in tearDown */
    private array $createdClubIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // user.functions.php transitively loads season, series, team, logging, common, etc.
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'club.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        // Super-admin access so all permission-guarded paths can be exercised.
        $_SESSION['userproperties']['userrole']['superadmin'] = true;

        // Disable persistent cache to avoid stale SELECT results after writes.
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdClubIds as $clubId) {
            DBQuery(sprintf("DELETE FROM uo_club WHERE club_id=%d", (int) $clubId));
        }
        $this->createdClubIds = [];
        unset($_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    /** Insert a club directly and register for cleanup. Returns the club_id. */
    private function insertClub(string $name, int $valid = 1): int
    {
        DBQuery(sprintf(
            "INSERT INTO uo_club (name, valid) VALUES ('%s', %d)",
            DBEscapeString($name),
            $valid
        ));
        $id = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdClubIds[] = $id;
        return $id;
    }

    // --- ClubName ---

    public function testClubNameReturnsNameForKnownClub(): void
    {
        $id = $this->insertClub('Test Club Alpha');
        $this->assertSame('Test Club Alpha', ClubName($id));
    }

    public function testClubNameReturnsFalseForUnknownClub(): void
    {
        $this->assertFalse((bool) ClubName(99999));
    }

    // --- ClubInfo ---

    public function testClubInfoReturnsRowForKnownClub(): void
    {
        $id = $this->insertClub('Test Club Info');
        $info = ClubInfo($id);
        $this->assertIsArray($info);
        $this->assertSame('Test Club Info', $info['name']);
        $this->assertArrayHasKey('club_id', $info);
        $this->assertArrayHasKey('valid', $info);
    }

    public function testClubInfoReturnsFalseForUnknownClub(): void
    {
        $result = ClubInfo(99999);
        $this->assertFalse((bool) $result);
    }

    // --- ClubList ---

    public function testClubListReturnsAllClubs(): void
    {
        $id = $this->insertClub('List Club Beta');
        $list = ClubList();
        $ids = array_column($list, 'club_id');
        $this->assertContains((string) $id, array_map('strval', $ids));
    }

    public function testClubListFiltersByValidFlag(): void
    {
        $validId   = $this->insertClub('Valid Club',   1);
        $invalidId = $this->insertClub('Invalid Club', 0);

        $validOnly = ClubList(true);
        $validIds = array_map('intval', array_column($validOnly, 'club_id'));

        $this->assertContains($validId, $validIds);
        $this->assertNotContains($invalidId, $validIds);
    }

    public function testClubListFiltersByNamePrefix(): void
    {
        $this->insertClub('Prefix Club Z');
        $this->insertClub('Other Club Y');

        $filtered = ClubList(false, 'P');
        $names = array_column($filtered, 'name');
        foreach ($names as $n) {
            $this->assertSame('p', strtolower(substr($n, 0, 1)));
        }
    }

    public function testClubListAllNameFilterReturnsEverything(): void
    {
        $id = $this->insertClub('All Filter Club');
        $list = ClubList(false, 'ALL');
        $ids = array_map('intval', array_column($list, 'club_id'));
        $this->assertContains($id, $ids);
    }

    public function testClubListNumericNameFilterReturnsDigitStartClubs(): void
    {
        $numId  = $this->insertClub('1st Club');
        $letId  = $this->insertClub('Letter Club');

        $filtered = ClubList(false, '#');
        $ids = array_map('intval', array_column($filtered, 'club_id'));

        $this->assertContains($numId, $ids);
        $this->assertNotContains($letId, $ids);
    }

    public function testClubListCombinedValidAndNameFilter(): void
    {
        $match   = $this->insertClub('Zeta Valid',   1);
        $invalid = $this->insertClub('Zeta Invalid', 0);

        $filtered = ClubList(true, 'Z');
        $ids = array_map('intval', array_column($filtered, 'club_id'));

        $this->assertContains($match, $ids);
        $this->assertNotContains($invalid, $ids);
    }

    // --- ClubId ---

    public function testClubIdReturnsIdForKnownName(): void
    {
        $id = $this->insertClub('Unique Name For Id Test');
        $this->assertSame((string) $id, (string) ClubId('Unique Name For Id Test'));
    }

    public function testClubIdReturnsFalseForUnknownName(): void
    {
        $this->assertFalse((bool) ClubId('Nonexistent Club XYZ'));
    }

    // --- ClubNumOfTeams ---

    public function testClubNumOfTeamsReturnsZeroForClubWithNoTeams(): void
    {
        $id = $this->insertClub('Empty Club');
        $this->assertSame(0, (int) ClubNumOfTeams($id));
    }

    // --- CanDeleteClub ---

    public function testCanDeleteClubReturnsTrueWhenNoTeamsReferenceIt(): void
    {
        $id = $this->insertClub('Deleteable Club');
        $this->assertTrue(CanDeleteClub($id));
    }

    // --- SetClubName ---

    public function testSetClubNameUpdatesName(): void
    {
        $id = $this->insertClub('Original Name');
        SetClubName($id, 'Updated Name');
        $this->assertSame('Updated Name', ClubName($id));
    }

    // --- SetClubValidity ---

    public function testSetClubValidityTogglesValidFlag(): void
    {
        $id = $this->insertClub('Validity Test Club', 1);

        SetClubValidity($id, 0);
        $info = ClubInfo($id);
        $this->assertSame('0', (string) $info['valid']);

        SetClubValidity($id, 1);
        $info = ClubInfo($id);
        $this->assertSame('1', (string) $info['valid']);
    }

    // --- AddClub and RemoveClub ---

    public function testAddClubCreatesClubAndRemoveClubDeletesIt(): void
    {
        $clubId = AddClub(100, 'Test Add Club');
        $this->assertGreaterThan(0, (int) $clubId);
        $this->createdClubIds[] = (int) $clubId;

        $this->assertTrue(CanDeleteClub($clubId));
        $ok = RemoveClub($clubId);
        $this->assertTrue($ok);

        // Remove from cleanup since we just deleted it.
        $this->createdClubIds = array_diff($this->createdClubIds, [(int) $clubId]);

        $this->assertFalse((bool) ClubName($clubId));
    }

    // --- ClubTeams ---

    public function testClubTeamsReturnsEmptyForClubWithNoTeams(): void
    {
        $id = $this->insertClub('Teams Club');
        $teams = ClubTeams($id, 'HRN2026');
        $this->assertIsArray($teams);
        $this->assertCount(0, $teams);
    }

    // --- ClubTeamsHistory ---

    public function testClubTeamsHistoryReturnsEmptyForClubWithNoTeams(): void
    {
        $id = $this->insertClub('History Club');
        $history = ClubTeamsHistory($id);
        $this->assertIsArray($history);
        $this->assertCount(0, $history);
    }

    // --- SetClubProfile ---

    public function testSetClubProfileUpdatesClubRow(): void
    {
        $id = $this->insertClub('Profile Club');

        $profile = [
            'club_id'      => $id,
            'name'         => 'Profile Club Updated',
            'contacts'     => 'contact@example.com',
            'country'      => 0,
            'city'         => 'Helsinki',
            'founded'      => '2000',
            'story'        => 'A great club.',
            'achievements' => 'Winners.',
            'valid'        => 1,
        ];

        // teamId=300 is a fixture team; super-admin bypasses club-match check.
        $result = SetClubProfile(300, $profile);
        $this->assertNotFalse($result);

        $info = ClubInfo($id);
        $this->assertSame('Profile Club Updated', $info['name']);
        $this->assertSame('Helsinki', $info['city']);
    }

    public function testSetClubProfileWithPositiveCountry(): void
    {
        $id = $this->insertClub('Country Profile Club');

        $profile = [
            'club_id'      => $id,
            'name'         => 'Country Profile Club',
            'contacts'     => '',
            'country'      => 1064,
            'city'         => 'Tampere',
            'founded'      => '2010',
            'story'        => '',
            'achievements' => '',
            'valid'        => 1,
        ];

        $result = SetClubProfile(300, $profile);
        $this->assertNotFalse($result);

        $info = ClubInfo($id);
        $this->assertSame('1064', (string) $info['country']);
    }

    // --- SetClubProfileImage and RemoveClubProfileImage ---

    public function testSetClubProfileImageUpdatesProfileImageField(): void
    {
        $id = $this->insertClub('Image Club');

        SetClubProfileImage(300, $id, 'test-image.jpg');

        $info = ClubInfo($id);
        $this->assertSame('test-image.jpg', $info['profile_image']);
    }

    public function testRemoveClubProfileImageClearsFieldWhenFileAbsent(): void
    {
        $id = $this->insertClub('Remove Image Club');
        // Set profile_image directly so we control the value without depending
        // on SetClubProfileImage also working correctly in this test.
        DBQuery(sprintf(
            "UPDATE uo_club SET profile_image='nonexistent.jpg' WHERE club_id=%d",
            $id
        ));
        $beforeInfo = ClubInfo($id);
        $this->assertSame('nonexistent.jpg', $beforeInfo['profile_image'], 'Direct UPDATE should have set profile_image');

        // RemoveClubProfileImage checks is_file(); since the file doesn't exist
        // the unlink is skipped, but the DB NULL update still runs.
        RemoveClubProfileImage(300, $id);

        $info = ClubInfo($id);
        $this->assertNull($info['profile_image']);
    }

    public function testRemoveClubProfileImageWithNoImageIsNoOp(): void
    {
        $id = $this->insertClub('No Image Club');
        // profile_image is NULL by default; function body runs but skips inner block.
        RemoveClubProfileImage(300, $id);

        $info = ClubInfo($id);
        $this->assertNull($info['profile_image']);
    }

    // --- UploadClubImage (error-return branches only) ---

    public function testUploadClubImageReturnsTooLargeWarningWhenOversized(): void
    {
        $id = $this->insertClub('Upload Club Large');
        $_FILES['picture'] = ['size' => 6 * 1024 * 1024, 'type' => '', 'tmp_name' => ''];

        $result = UploadClubImage(300, $id);

        $this->assertIsString($result);
        $this->assertStringContainsString('warning', $result);
    }

    public function testUploadClubImageReturnsFormatWarningForNonImageType(): void
    {
        $id = $this->insertClub('Upload Club Format');
        $_FILES['picture'] = ['size' => 100, 'type' => 'text/plain', 'tmp_name' => ''];

        $result = UploadClubImage(300, $id);

        $this->assertIsString($result);
        $this->assertStringContainsString('warning', $result);
    }

    // --- AddClubProfileUrl and RemoveClubProfileUrl ---

    public function testAddAndRemoveClubProfileUrl(): void
    {
        $id = $this->insertClub('URL Club');

        $added = AddClubProfileUrl(300, $id, 'homepage', 'https://example.com', 'Homepage');
        $this->assertNotFalse($added);

        // Find the url_id we just created.
        $urlId = (int) DBQueryToValue(
            sprintf("SELECT url_id FROM uo_urls WHERE owner='club' AND owner_id=%d ORDER BY url_id DESC LIMIT 1", $id)
        );
        $this->assertGreaterThan(0, $urlId);

        $removed = RemoveClubProfileUrl(300, $id, $urlId);
        $this->assertNotFalse($removed);

        $count = (int) DBQueryToValue(
            sprintf("SELECT COUNT(*) FROM uo_urls WHERE url_id=%d", $urlId)
        );
        $this->assertSame(0, $count);
    }
}
