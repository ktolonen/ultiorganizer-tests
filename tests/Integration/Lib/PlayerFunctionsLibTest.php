<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PlayerFunctionsLibTest extends TestCase
{
    // Fixture: player 800 ('Ari Ace', team=300, num=8, accredited=1, profile_id=NULL),
    // game 700 (hometeam=300 vs visitorteam=301, homescore=15/visitorscore=11, hasstarted=1),
    // uo_played (800,700,num=8), uo_goal: player 800 scored once and assisted once in game 700.

    private ?int $testPlayerId    = null;
    private ?int $testProfileId   = null;
    /** Second player with no profile, used for SetPlayerProfile new-profile path. */
    private ?int $testPlayerId2   = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'player.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';

        // Insert test profile with enough fields for FindExistingPlayerProfileId tests.
        DBQuery("INSERT INTO uo_player_profile (firstname, lastname, accreditation_id, email, birthdate, num)
                 VALUES ('Pekka', 'Probe', 'TEST-ACCRID-999', 'pekka@probe.example', '1990-01-15', 7)");
        $this->testProfileId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        // Insert test player linked to the profile.
        DBQuery(sprintf(
            "INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, profile_id)
             VALUES ('Pekka', 'Probe', 300, 7, 'TEST-ACCRID-999', 1, %d)",
            $this->testProfileId
        ));
        $this->testPlayerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        // Player without a profile, used for new-profile INSERT paths.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accredited, profile_id)
                 VALUES ('BrandNew', 'Player', 300, 5, 1, NULL)");
        $this->testPlayerId2 = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
    }

    protected function tearDown(): void
    {
        if ($this->testPlayerId2 !== null) {
            $profileId2 = (int) DBQueryToValue(sprintf(
                "SELECT profile_id FROM uo_player WHERE player_id=%d", $this->testPlayerId2
            ));
            if ($profileId2 > 0) {
                DBQuery(sprintf("DELETE FROM uo_urls WHERE owner='player' AND owner_id=%d", $profileId2));
                DBQuery(sprintf("DELETE FROM uo_player_profile WHERE profile_id=%d", $profileId2));
            }
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $this->testPlayerId2));
            $this->testPlayerId2 = null;
        }
        if ($this->testProfileId !== null) {
            DBQuery(sprintf("DELETE FROM uo_urls WHERE owner='player' AND owner_id=%d", $this->testProfileId));
        }
        if ($this->testPlayerId !== null) {
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        }
        if ($this->testProfileId !== null) {
            DBQuery(sprintf("DELETE FROM uo_player_profile WHERE profile_id=%d", $this->testProfileId));
        }
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- NormalizedPlayerNumberSql ---

    public function testNormalizedPlayerNumberSqlReturnsNullForEmptyString(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql(''));
    }

    public function testNormalizedPlayerNumberSqlReturnsNullForWhitespaceOnly(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql('  '));
    }

    public function testNormalizedPlayerNumberSqlReturnsNullForNull(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql(null));
    }

    public function testNormalizedPlayerNumberSqlReturnsNullForNonNumericString(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql('abc'));
    }

    public function testNormalizedPlayerNumberSqlReturnsNullForNegativeNumber(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql('-1'));
    }

    public function testNormalizedPlayerNumberSqlReturnsNullForNumberAboveMaxRange(): void
    {
        $this->assertSame('NULL', NormalizedPlayerNumberSql('100'));
    }

    public function testNormalizedPlayerNumberSqlReturnsStringForValidNumber(): void
    {
        $this->assertSame('42', NormalizedPlayerNumberSql('42'));
    }

    public function testNormalizedPlayerNumberSqlReturnsZeroString(): void
    {
        $this->assertSame('0', NormalizedPlayerNumberSql(0));
    }

    public function testNormalizedPlayerNumberSqlTrimsAndConvertsValidNumber(): void
    {
        $this->assertSame('5', NormalizedPlayerNumberSql(' 5 '));
    }

    // --- SetPlayerProfileDefaultNumberIfEmpty ---

    public function testSetPlayerProfileDefaultNumberIfEmptyReturnsFalseForEmptyProfileId(): void
    {
        $this->assertFalse(SetPlayerProfileDefaultNumberIfEmpty(0, 5));
    }

    public function testSetPlayerProfileDefaultNumberIfEmptyReturnsFalseForZeroNumber(): void
    {
        $this->assertFalse(SetPlayerProfileDefaultNumberIfEmpty($this->testProfileId, 0));
    }

    public function testSetPlayerProfileDefaultNumberIfEmptyUpdatesWhenNumberNotSet(): void
    {
        // testProfile currently has num=7. Set it to NULL first so the update can fire.
        DBQuery(sprintf("UPDATE uo_player_profile SET num=NULL WHERE profile_id=%d", $this->testProfileId));
        SetPlayerProfileDefaultNumberIfEmpty($this->testProfileId, 88);
        $num = (int) DBQueryToValue(sprintf(
            "SELECT num FROM uo_player_profile WHERE profile_id=%d", $this->testProfileId
        ));
        $this->assertSame(88, $num);
    }

    // --- PlayerInfo ---

    public function testPlayerInfoReturnsRowForFixturePlayer(): void
    {
        $info = PlayerInfo(800);
        $this->assertIsArray($info);
        $this->assertSame('800', (string) $info['player_id']);
        $this->assertSame('Ari', $info['firstname']);
        $this->assertSame('Ace', $info['lastname']);
    }

    public function testPlayerInfoReturnsFalseForUnknownPlayer(): void
    {
        $this->assertFalse((bool) PlayerInfo(99999));
    }

    // --- PlayerName ---

    public function testPlayerNameReturnsFullNameForFixturePlayer(): void
    {
        $this->assertSame('Ari Ace', PlayerName(800));
    }

    public function testPlayerNameReturnsEmptyStringForUnknownPlayer(): void
    {
        $this->assertSame('', PlayerName(99999));
    }

    // --- PlayerProfile ---

    public function testPlayerProfileReturnsRowForTestProfile(): void
    {
        $profile = PlayerProfile($this->testProfileId);
        $this->assertIsArray($profile);
        $this->assertSame('Pekka', $profile['firstname']);
        $this->assertSame('Probe', $profile['lastname']);
    }

    public function testPlayerProfileReturnsFalseForUnknownProfile(): void
    {
        $this->assertFalse((bool) PlayerProfile(99999));
    }

    // --- PlayerLatestId ---

    public function testPlayerLatestIdReturnsPlayerIdForTestProfile(): void
    {
        $latest = (int) PlayerLatestId($this->testProfileId);
        $this->assertSame($this->testPlayerId, $latest);
    }

    public function testPlayerLatestIdReturnsNegativeOneForEmptyProfileId(): void
    {
        $this->assertSame(-1, (int) PlayerLatestId(0));
    }

    // --- PlayerListAll ---

    public function testPlayerListAllReturnsResourceWithAccreditedPlayers(): void
    {
        $result = PlayerListAll();
        $this->assertNotFalse($result);
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $ids = array_column($rows, 'player_id');
        $this->assertContains((string) $this->testPlayerId, $ids);
    }

    public function testPlayerListAllWithLastnameFilterNarrowsResults(): void
    {
        $result = PlayerListAll('Probe');
        $this->assertNotFalse($result);
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('P', strtoupper($row['lastname']));
        }
    }

    // --- PlayerListAllArray ---

    public function testPlayerListAllArrayReturnsArrayOfAccreditedPlayers(): void
    {
        $players = PlayerListAllArray();
        $this->assertIsArray($players);
        $this->assertNotEmpty($players);
    }

    // --- SeasonPlayersMissingNumbers ---

    public function testSeasonPlayersMissingNumbersReturnsArray(): void
    {
        $result = SeasonPlayersMissingNumbers('HRN2026');
        $this->assertIsArray($result);
    }

    // --- SeasonPlayersDuplicateNumbers ---

    public function testSeasonPlayersDuplicateNumbersReturnsArray(): void
    {
        $result = SeasonPlayersDuplicateNumbers('HRN2026');
        $this->assertIsArray($result);
    }

    // --- SearchPlayerProfiles ---

    public function testSearchPlayerProfilesReturnsArrayForEmptySearch(): void
    {
        $result = SearchPlayerProfiles('', '');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testSearchPlayerProfilesFindsTestProfileByName(): void
    {
        $result = SearchPlayerProfiles('Pekka', 'Probe');
        $this->assertIsArray($result);
        $profileIds = array_column($result, 'profile_id');
        $this->assertContains((string) $this->testProfileId, $profileIds);
    }

    public function testSearchPlayerProfilesWithIncludeEmailReturnsEmailColumn(): void
    {
        $result = SearchPlayerProfiles('Pekka', 'Probe', true);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('email', $result[0]);
    }

    // --- PlayerInfoByAccrId ---

    public function testPlayerInfoByAccrIdReturnsFalseForUnknownAccrId(): void
    {
        $result = PlayerInfoByAccrId('NO-SUCH-ACCRID', 100);
        $this->assertFalse((bool) $result);
    }

    // --- Players ---

    public function testPlayersReturnsResourceForDefaultParameters(): void
    {
        $result = Players();
        $this->assertNotFalse($result);
    }

    // --- PlayerNumber ---

    public function testPlayerNumberReturnsGameNumberFromUoPlayedForFixturePlayer(): void
    {
        // uo_played (800, 700, num=8) → game number is 8
        $this->assertSame(8, PlayerNumber(800, 700));
    }

    public function testPlayerNumberFallsBackToDefaultNumWhenNoUoPlayedRow(): void
    {
        // Player 800 has no uo_played row for game 701 → falls back to player.num=8
        $this->assertSame(8, PlayerNumber(800, 701));
    }

    public function testPlayerNumberReturnsNegativeOneForUnknownPlayer(): void
    {
        $this->assertSame(-1, PlayerNumber(99999, 700));
    }

    // --- PlayerSeasonGames ---

    public function testPlayerSeasonGamesReturnsGamesWithScoringActivity(): void
    {
        // Player 800 scored and assisted in game 700.
        $games = PlayerSeasonGames(800, 'HRN2026');
        $this->assertIsArray($games);
        $ids = array_column($games, 'game_id');
        $this->assertContains('700', $ids);
    }

    // --- PlayerSeasonPlayedGames ---

    public function testPlayerSeasonPlayedGamesReturnsCountForFixturePlayer(): void
    {
        $count = (int) PlayerSeasonPlayedGames(800, 'HRN2026');
        $this->assertGreaterThanOrEqual(1, $count);
    }

    // --- PlayerSeasonTeamPlayedGames ---

    public function testPlayerSeasonTeamPlayedGamesReturnsCountForFixturePlayer(): void
    {
        $count = (int) PlayerSeasonTeamPlayedGames(800, 300, 'HRN2026');
        $this->assertGreaterThanOrEqual(0, $count);
    }

    // --- PlayerSeasonPasses ---

    public function testPlayerSeasonPassesReturnsCountForFixturePlayer(): void
    {
        // Player 800 assisted goal 3 in game 700.
        $passes = (int) PlayerSeasonPasses(800, 'HRN2026');
        $this->assertSame(1, $passes);
    }

    // --- PlayerSeasonGoals ---

    public function testPlayerSeasonGoalsReturnsCountForFixturePlayer(): void
    {
        // Player 800 scored goal 1 in game 700.
        $goals = (int) PlayerSeasonGoals(800, 'HRN2026');
        $this->assertSame(1, $goals);
    }

    // --- PlayerSeasonDefenses ---

    public function testPlayerSeasonDefensesReturnsZeroForFixturePlayer(): void
    {
        $defenses = (int) PlayerSeasonDefenses(800, 'HRN2026');
        $this->assertSame(0, $defenses);
    }

    // --- PlayerSeasonCallahanGoals ---

    public function testPlayerSeasonCallahanGoalsReturnsZeroForFixturePlayer(): void
    {
        $callahans = (int) PlayerSeasonCallahanGoals(800, 'HRN2026');
        $this->assertSame(0, $callahans);
    }

    // --- PlayerSeasonWins ---

    public function testPlayerSeasonWinsReturnsWinCountForFixturePlayer(): void
    {
        // Game 700: team 300 won 15-11; player 800 was on team 300.
        $wins = (int) PlayerSeasonWins(800, 300, 'HRN2026');
        $this->assertSame(1, $wins);
    }

    // --- PlayerGameEvents ---

    public function testPlayerGameEventsReturnsScoringEventsForFixturePlayer(): void
    {
        // Player 800 scored goal 1 and assisted goal 3 in game 700.
        $events = PlayerGameEvents(800, 700);
        $this->assertIsArray($events);
        $this->assertCount(2, $events);
    }

    public function testPlayerGameEventsReturnsEmptyForPlayerWithNoEvents(): void
    {
        $events = PlayerGameEvents(99999, 700);
        $this->assertIsArray($events);
        $this->assertCount(0, $events);
    }

    // --- FindExistingPlayerProfileId ---

    public function testFindExistingPlayerProfileIdReturnsZeroForNonArrayInput(): void
    {
        $this->assertSame(0, FindExistingPlayerProfileId('notanarray'));
    }

    public function testFindExistingPlayerProfileIdReturnsZeroForEmptyArray(): void
    {
        $this->assertSame(0, FindExistingPlayerProfileId([]));
    }

    public function testFindExistingPlayerProfileIdMatchesByAccreditationId(): void
    {
        $result = FindExistingPlayerProfileId(['accreditation_id' => 'TEST-ACCRID-999']);
        $this->assertSame($this->testProfileId, $result);
    }

    public function testFindExistingPlayerProfileIdMatchesByEmailAndName(): void
    {
        $result = FindExistingPlayerProfileId([
            'firstname'  => 'Pekka',
            'lastname'   => 'Probe',
            'email'      => 'pekka@probe.example',
        ]);
        $this->assertSame($this->testProfileId, $result);
    }

    public function testFindExistingPlayerProfileIdMatchesByNameAndBirthdate(): void
    {
        $result = FindExistingPlayerProfileId([
            'firstname' => 'Pekka',
            'lastname'  => 'Probe',
            'birthdate' => '1990-01-15',
        ]);
        $this->assertSame($this->testProfileId, $result);
    }

    public function testFindExistingPlayerProfileIdReturnsZeroForNoMatch(): void
    {
        $result = FindExistingPlayerProfileId([
            'firstname'  => 'Nobody',
            'lastname'   => 'Nowhere',
            'email'      => 'nobody@nowhere.example',
        ]);
        $this->assertSame(0, $result);
    }

    // --- SetPlayerNumber ---

    public function testSetPlayerNumberUpdatesNumberForTestPlayer(): void
    {
        SetPlayerNumber($this->testPlayerId, 55);
        $num = (int) DBQueryToValue(sprintf(
            "SELECT num FROM uo_player WHERE player_id=%d", $this->testPlayerId
        ));
        $this->assertSame(55, $num);
    }

    public function testSetPlayerNumberSetsNullForInvalidNumber(): void
    {
        SetPlayerNumber($this->testPlayerId, 'bad');
        $result = DBQueryToValue(sprintf(
            "SELECT num FROM uo_player WHERE player_id=%d", $this->testPlayerId
        ));
        $this->assertNull($result);
    }

    // --- SetPlayer ---

    public function testSetPlayerUpdatesPlayerFields(): void
    {
        SetPlayer($this->testPlayerId, 33, 'Updated', 'Probe', 'NEW-ACCRID', $this->testProfileId);
        $info = PlayerInfo($this->testPlayerId);
        $this->assertSame('Updated', $info['firstname']);
        $this->assertSame('33', (string) $info['num']);
    }

    // --- SetPlayerProfile ---

    public function testSetPlayerProfileUpdatesExistingProfile(): void
    {
        $profile = [
            'accreditation_id' => 'TEST-ACCRID-999',
            'firstname' => 'PekkaUpdated',
            'lastname' => 'Probe',
            'num' => '7',
            'email' => 'pekka@probe.example',
            'nickname' => '',
            'gender' => '',
            'info' => '',
            'national_id' => '',
            'birthdate' => '1990-01-15',
            'birthplace' => '',
            'nationality' => '',
            'throwing_hand' => '',
            'height' => '',
            'weight' => '',
            'position' => '',
            'story' => '',
            'achievements' => '',
            'public' => '1',
        ];
        SetPlayerProfile(300, $this->testPlayerId, $profile);
        $updated = PlayerProfile($this->testProfileId);
        $this->assertSame('PekkaUpdated', $updated['firstname']);
    }

    // --- UpdatePlayerProfile ---

    public function testUpdatePlayerProfileUpdatesNamesAndNumber(): void
    {
        UpdatePlayerProfile($this->testProfileId, 'NewFirst', 'NewLast', 99);
        $profile = PlayerProfile($this->testProfileId);
        $this->assertSame('NewFirst', $profile['firstname']);
        $this->assertSame('NewLast', $profile['lastname']);
        $this->assertSame('99', (string) $profile['num']);
    }

    // --- AddPlayerProfileUrl + RemovePlayerProfileUrl ---

    public function testAddPlayerProfileUrlInsertsUrlRow(): void
    {
        $before = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_urls WHERE owner='player' AND owner_id=%d", $this->testProfileId
        ));
        AddPlayerProfileUrl($this->testPlayerId, 'website', 'https://example.com', 'My Site');
        $after = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_urls WHERE owner='player' AND owner_id=%d", $this->testProfileId
        ));
        $this->assertSame($before + 1, $after);
    }

    public function testRemovePlayerProfileUrlDeletesUrlRow(): void
    {
        AddPlayerProfileUrl($this->testPlayerId, 'website', 'https://example.com', 'My Site');
        $urlId = (int) DBQueryToValue(sprintf(
            "SELECT url_id FROM uo_urls WHERE owner='player' AND owner_id=%d LIMIT 1", $this->testProfileId
        ));
        RemovePlayerProfileUrl($this->testPlayerId, $urlId);
        $count = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_urls WHERE url_id=%d", $urlId
        ));
        $this->assertSame(0, $count);
    }

    // --- SetPlayerProfileImage ---

    public function testSetPlayerProfileImageSetsImageFilenameInProfile(): void
    {
        SetPlayerProfileImage($this->testPlayerId, 'testimage.jpg');
        $profile = PlayerProfile($this->testProfileId);
        $this->assertSame('testimage.jpg', $profile['profile_image']);
    }

    // --- RemovePlayerProfileImage ---

    public function testRemovePlayerProfileImageIsNoOpWhenNoImageSet(): void
    {
        // testProfile has no profile_image → function returns without touching files.
        RemovePlayerProfileImage($this->testPlayerId);
        $profile = PlayerProfile($this->testProfileId);
        $this->assertNull($profile['profile_image']);
    }

    public function testRemovePlayerProfileImageClearsImageAndUpdatesDb(): void
    {
        SetPlayerProfileImage($this->testPlayerId, 'remove_me.jpg');
        RemovePlayerProfileImage($this->testPlayerId);
        $profile = PlayerProfile($this->testProfileId);
        $this->assertNull($profile['profile_image']);
    }

    // --- FindExistingPlayerProfileId (additional branches) ---

    public function testFindExistingPlayerProfileIdMatchesByEmailNameAndBirthdate(): void
    {
        // Covers the emailNameBirthdateCondition path (email+name+birthdate together).
        $result = FindExistingPlayerProfileId([
            'firstname'  => 'Pekka',
            'lastname'   => 'Probe',
            'email'      => 'pekka@probe.example',
            'birthdate'  => '1990-01-15',
        ]);
        $this->assertSame($this->testProfileId, $result);
    }

    public function testFindExistingPlayerProfileIdReturnsZeroForNameBirthdateNoMatch(): void
    {
        // Covers the final return 0 when name+birthdate present but no matching profile.
        $result = FindExistingPlayerProfileId([
            'firstname' => 'Nobody',
            'lastname'  => 'Nowhere',
            'birthdate' => '1900-01-01',
        ]);
        $this->assertSame(0, $result);
    }

    // --- PlayerNumber (else branch) ---

    public function testPlayerNumberReturnsNegativeOneForPlayerWithNullNum(): void
    {
        // A player with num=NULL and no uo_played row triggers the else { return -1 } branch.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accredited, profile_id)
                 VALUES ('TmpNull', 'Player', 300, NULL, 1, NULL)");
        $tmpId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        try {
            $this->assertSame(-1, PlayerNumber($tmpId, 700));
        } finally {
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $tmpId));
        }
    }

    // --- SetPlayerProfile (new-profile INSERT path) ---

    public function testSetPlayerProfileCreatesNewProfileForPlayerWithoutOne(): void
    {
        $profile = [
            'accreditation_id' => '',
            'firstname'        => 'BrandNew',
            'lastname'         => 'Player',
            'num'              => '',
            'email'            => 'brandnew@player.example',
            'nickname'         => '',
            'gender'           => '',
            'info'             => '',
            'national_id'      => '',
            'birthdate'        => '1995-06-15',
            'birthplace'       => '',
            'nationality'      => '',
            'throwing_hand'    => '',
            'height'           => '',
            'weight'           => '',
            'position'         => '',
            'story'            => '',
            'achievements'     => '',
            'public'           => '1',
        ];
        SetPlayerProfile(300, $this->testPlayerId2, $profile);
        $updated = PlayerInfo($this->testPlayerId2);
        $this->assertGreaterThan(0, (int) $updated['profile_id']);
    }

    // --- CreatePlayerProfile ---

    public function testCreatePlayerProfileCreatesNewProfileForPlayerWithoutOne(): void
    {
        CreatePlayerProfile($this->testPlayerId2);
        $updated = PlayerInfo($this->testPlayerId2);
        $this->assertGreaterThan(0, (int) $updated['profile_id']);
    }

    // --- PlayersToCsv ---

    public function testPlayersToCsvReturnsStringWithFixtureSeasonData(): void
    {
        $csv = PlayersToCsv('HRN2026', ',');
        $this->assertIsString($csv);
    }

    // --- UploadPlayerImage ---

    public function testUploadPlayerImageRejectsOversizeFile(): void
    {
        $_FILES['picture'] = ['name' => 'big.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/big', 'error' => 0, 'size' => 6 * 1024 * 1024];
        try {
            $result = UploadPlayerImage(800);
            $this->assertStringContainsString('too large', strtolower(strip_tags($result)));
        } finally {
            unset($_FILES['picture']);
        }
    }

    public function testUploadPlayerImageRejectsNonImageType(): void
    {
        $_FILES['picture'] = ['name' => 'x.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 100];
        try {
            $result = UploadPlayerImage(800);
            $this->assertStringContainsString('not supported', strtolower(strip_tags($result)));
        } finally {
            unset($_FILES['picture']);
        }
    }
}
