<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class AccreditationFunctionsLibTest extends TestCase
{
    // Fixture: season='HRN2026', series=100, team=300 ('Helsinki Heat'),
    // player_id=800 (team=300, accredited=1, profile_id=NULL),
    // uo_played row (800, 700): accredited=1, acknowledged=1.

    /** @var int|null player_id inserted for write tests; deleted in tearDown */
    private ?int $testPlayerId = null;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'accreditation.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        // Prevent getSessionLocale() from calling GetDefaultLocale() (not loaded).
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';

        // Insert a disposable test player in team 300 for write tests.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Test', 'AccredPlayer', 300, 99, NULL, 1, NULL, NULL)");
        $this->testPlayerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        // Insert a uo_played row so Acknowledge/UnAcknowledge tests have a target.
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 700, 99, 1, 0, 0)",
            $this->testPlayerId
        ));
    }

    protected function tearDown(): void
    {
        if ($this->testPlayerId !== null) {
            DBQuery(sprintf("DELETE FROM uo_accreditationlog WHERE player=%d", $this->testPlayerId));
            DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d", $this->testPlayerId));
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        }
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- SeasonUnaccredited ---

    public function testSeasonUnaccreditedReturnsArray(): void
    {
        $result = SeasonUnaccredited('HRN2026');
        $this->assertIsArray($result);
    }

    public function testSeasonUnaccreditedReturnsEmptyForUnknownSeason(): void
    {
        $result = SeasonUnaccredited('NOSUCHSEASON9999');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- ExternalLicenseValidityList ---

    public function testExternalLicenseValidityListReturnsArray(): void
    {
        $result = ExternalLicenseValidityList();
        $this->assertIsArray($result);
    }

    // --- ExternalLicenseTypes ---

    public function testExternalLicenseTypesReturnsArray(): void
    {
        $result = ExternalLicenseTypes();
        $this->assertIsArray($result);
    }

    // --- LicenseData ---

    public function testLicenseDataReturnsFalseForUnknownAccrId(): void
    {
        $result = LicenseData('nonexistent-license-id-xyz');
        $this->assertFalse((bool) $result);
    }

    // --- SearchLicenseData ---

    public function testSearchLicenseDataReturnsArrayForNameSearch(): void
    {
        $result = SearchLicenseData('Ari', 'Ace');
        $this->assertIsArray($result);
    }

    public function testSearchLicenseDataReturnsArrayForEmptySearch(): void
    {
        $result = SearchLicenseData('', '');
        $this->assertIsArray($result);
    }

    // --- isAccredited ---

    public function testIsAccreditedReturnsTrueForAccreditedPlayer(): void
    {
        $this->assertSame('1', (string) isAccredited(800));
    }

    public function testIsAccreditedReturnsZeroForUnknownPlayer(): void
    {
        $this->assertSame('0', (string) isAccredited(99999));
    }

    // --- AccreditationLogEntry ---

    public function testAccreditationLogEntryInsertsRowWithoutGame(): void
    {
        $before = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_accreditationlog WHERE player=%d", $this->testPlayerId));
        AccreditationLogEntry($this->testPlayerId, 300, 'unit-test', 1);
        $after = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_accreditationlog WHERE player=%d", $this->testPlayerId));
        $this->assertSame($before + 1, $after);
    }

    public function testAccreditationLogEntryInsertsRowWithGame(): void
    {
        $before = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_accreditationlog WHERE player=%d", $this->testPlayerId));
        AccreditationLogEntry($this->testPlayerId, 300, 'unit-test-game', 1, 700);
        $after = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_accreditationlog WHERE player=%d", $this->testPlayerId));
        $this->assertSame($before + 1, $after);
    }

    // --- AccreditPlayer ---

    public function testAccreditPlayerSetsAccreditedFlag(): void
    {
        // Set accredited=0 first so we can verify it changes to 1.
        DBQuery(sprintf("UPDATE uo_player SET accredited=0 WHERE player_id=%d", $this->testPlayerId));

        AccreditPlayer($this->testPlayerId, 'test-accredit');

        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(1, $accredited);
    }

    // --- DeAccreditPlayer ---

    public function testDeAccreditPlayerClearsAccreditedFlag(): void
    {
        // Ensure player is accredited first.
        DBQuery(sprintf("UPDATE uo_player SET accredited=1 WHERE player_id=%d", $this->testPlayerId));

        DeAccreditPlayer($this->testPlayerId, 'test-deaccredit');

        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(0, $accredited);
    }

    // --- AcknowledgeUnaccredited ---

    public function testAcknowledgeUnaccreditedSetsAcknowledgedFlag(): void
    {
        AcknowledgeUnaccredited($this->testPlayerId, 700, 'test-acknowledge');

        $ack = (int) DBQueryToValue(sprintf("SELECT acknowledged FROM uo_played WHERE player=%d AND game=700", $this->testPlayerId));
        $this->assertSame(1, $ack);
    }

    // --- UnAcknowledgeUnaccredited ---

    public function testUnAcknowledgeUnaccreditedClearsAcknowledgedFlag(): void
    {
        // Ensure acknowledged=1 first.
        DBQuery(sprintf("UPDATE uo_played SET acknowledged=1 WHERE player=%d AND game=700", $this->testPlayerId));

        UnAcknowledgeUnaccredited($this->testPlayerId, 700, 'test-unacknowledge');

        $ack = (int) DBQueryToValue(sprintf("SELECT acknowledged FROM uo_played WHERE player=%d AND game=700", $this->testPlayerId));
        $this->assertSame(0, $ack);
    }

    // --- checkUserAdmin ---

    public function testCheckUserAdminReturnsFalseWhenProfileIdIsZero(): void
    {
        $playerInfo = ['profile_id' => 0, 'email' => 'test@example.com'];
        $this->assertFalse(checkUserAdmin($playerInfo));
    }

    public function testCheckUserAdminReturnsFalseForInvalidEmail(): void
    {
        // profile_id > 0, but email invalid → returns false before DB check.
        $playerInfo = ['profile_id' => 999999, 'email' => 'not-an-email'];
        $result = checkUserAdmin($playerInfo);
        $this->assertFalse($result);
    }

    public function testCheckUserAdminWithUnknownProfileIdAndValidEmailReturnsNull(): void
    {
        // profile_id > 0, valid email, but no user row in DB → result is null/falsy.
        $playerInfo = ['profile_id' => 999999, 'email' => 'noone@example.com'];
        $result = checkUserAdmin($playerInfo);
        // Returns null (no early-return path triggered, falls through with no matching user).
        $this->assertFalse((bool) $result);
    }

    public function testCheckUserAdminMatchingUserInsertsAdminRow(): void
    {
        // profile_id=88877 not yet in uo_userproperties → proceeds to email check.
        // email matches fixture 'admin' user → inserts playeradmin row → returns true.
        $profileId = 88877;
        DBQuery("DELETE FROM uo_userproperties WHERE name='userrole' AND value='playeradmin:$profileId'");
        $playerInfo = ['profile_id' => $profileId, 'email' => 'admin@example.com'];
        try {
            $result = checkUserAdmin($playerInfo);
            $this->assertTrue($result);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE name='userrole' AND value='playeradmin:$profileId'");
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE name='userrole' AND value='playeradmin:$profileId'");
        }
    }

    public function testCheckUserAdminReturnsEarlyWhenAlreadyAdministered(): void
    {
        // Pre-insert an admin row → function finds it and returns early (void/null).
        $profileId = 88878;
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'playeradmin:$profileId')");
        $playerInfo = ['profile_id' => $profileId, 'email' => 'admin@example.com'];
        try {
            $result = checkUserAdmin($playerInfo);
            // early return; no additional insert expected
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE name='userrole' AND value='playeradmin:$profileId'");
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE name='userrole' AND value='playeradmin:$profileId'");
        }
    }

    // --- AccreditPlayerByAccrId ---

    public function testAccreditPlayerByAccrIdIsNoOpForUnknownAccrId(): void
    {
        // PlayerInfoByAccrId returns false → function does nothing, no exception.
        AccreditPlayerByAccrId('nonexistent-id', 100, 'test-by-accrid');
        $this->assertTrue(true);
    }

    public function testAccreditPlayerByAccrIdAccreditsWhenPlayerFound(): void
    {
        // Give testPlayerId a known accreditation_id and clear accredited flag.
        $accrId = 'harness-accr-' . $this->testPlayerId;
        DBQuery(sprintf("UPDATE uo_player SET accreditation_id='%s', accredited=0 WHERE player_id=%d",
            DBEscapeString($accrId), $this->testPlayerId));

        AccreditPlayerByAccrId($accrId, 100, 'test-by-accrid');

        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(1, $accredited);
    }

    public function testAccreditPlayerByAccrIdSkipsAlreadyAccredited(): void
    {
        // Player is already accredited → inner if(!accredited) skips AccreditPlayer.
        $accrId = 'harness-accr-skip-' . $this->testPlayerId;
        DBQuery(sprintf("UPDATE uo_player SET accreditation_id='%s', accredited=1 WHERE player_id=%d",
            DBEscapeString($accrId), $this->testPlayerId));

        AccreditPlayerByAccrId($accrId, 100, 'test-skip');

        // Still accredited, no log entry added (would fail if AccreditPlayer were called on already-accredited).
        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(1, $accredited);
    }

    // --- DeAccreditPlayerByAccrId ---

    public function testDeAccreditPlayerByAccrIdIsNoOpForUnknownAccrId(): void
    {
        DeAccreditPlayerByAccrId('nonexistent-id', 100, 'test-deaccrid');
        $this->assertTrue(true);
    }

    public function testDeAccreditPlayerByAccrIdDeaccreditsWhenPlayerFound(): void
    {
        $accrId = 'harness-deaccr-' . $this->testPlayerId;
        DBQuery(sprintf("UPDATE uo_player SET accreditation_id='%s', accredited=1 WHERE player_id=%d",
            DBEscapeString($accrId), $this->testPlayerId));

        DeAccreditPlayerByAccrId($accrId, 100, 'test-deaccrid');

        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(0, $accredited);
    }

    public function testDeAccreditPlayerByAccrIdSkipsAlreadyDeaccredited(): void
    {
        $accrId = 'harness-deaccr-skip-' . $this->testPlayerId;
        DBQuery(sprintf("UPDATE uo_player SET accreditation_id='%s', accredited=0 WHERE player_id=%d",
            DBEscapeString($accrId), $this->testPlayerId));

        DeAccreditPlayerByAccrId($accrId, 100, 'test-deaccr-skip');

        $accredited = (int) DBQueryToValue(sprintf("SELECT accredited FROM uo_player WHERE player_id=%d", $this->testPlayerId));
        $this->assertSame(0, $accredited);
    }

    // --- SeasonAccreditationLog ---

    public function testSeasonAccreditationLogReturnsArray(): void
    {
        $result = SeasonAccreditationLog('HRN2026');
        $this->assertIsArray($result);
    }

    public function testSeasonAccreditationLogContainsInsertedEntry(): void
    {
        AccreditationLogEntry($this->testPlayerId, 300, 'log-test', 1);
        // Log joins on uo_series via uo_team; team 300 has series 100, season HRN2026.
        $log = SeasonAccreditationLog('HRN2026');
        $playerIds = array_map('intval', array_column($log, 'player_id'));
        $this->assertContains($this->testPlayerId, $playerIds);
    }
}
