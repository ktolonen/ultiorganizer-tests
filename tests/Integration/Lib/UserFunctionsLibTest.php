<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class UserFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('user.functions.php', 'database_only');
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // --- Pure function tests ---

    public function testHashEqualsSafeMatchingStrings(): void
    {
        $this->assertTrue(hashEqualsSafe('abc', 'abc'));
    }

    public function testHashEqualsSafeDifferentStrings(): void
    {
        $this->assertFalse(hashEqualsSafe('abc', 'def'));
    }

    public function testIsLegacyMd5HashValid(): void
    {
        $this->assertTrue(isLegacyMd5Hash(md5('test')));
    }

    public function testIsLegacyMd5HashTooShort(): void
    {
        $this->assertFalse(isLegacyMd5Hash('abc123'));
    }

    public function testIsLegacyMd5HashNonHex(): void
    {
        $this->assertFalse(isLegacyMd5Hash(str_repeat('g', 32)));
    }

    public function testHashUserPassword(): void
    {
        $hash = hashUserPassword('secret');
        $this->assertTrue(password_verify('secret', $hash));
    }

    public function testVerifyUserPasswordWithBcrypt(): void
    {
        $hash = password_hash('mypass', PASSWORD_DEFAULT);
        $this->assertTrue(verifyUserPassword('mypass', $hash));
        $this->assertFalse(verifyUserPassword('wrong', $hash));
    }

    public function testVerifyUserPasswordWithLegacyMd5(): void
    {
        $hash = md5('oldpassword');
        $this->assertTrue(verifyUserPassword('oldpassword', $hash));
        $this->assertFalse(verifyUserPassword('wrongpassword', $hash));
    }

    public function testVerifyUserPasswordEmptyHash(): void
    {
        $this->assertFalse(verifyUserPassword('anything', ''));
        $this->assertFalse(verifyUserPassword('anything', null));
    }

    public function testVerifyUserPasswordWithRehashTriggeredByLegacyMd5(): void
    {
        $userId = 'testuser_rehash';
        $md5hash = md5('temp123');
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('$userId', '$md5hash', 'Temp User')");
        try {
            $this->assertTrue(verifyUserPassword('temp123', $md5hash, $userId));
            $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='$userId'");
            $this->assertFalse(isLegacyMd5Hash($row['password']));
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='$userId'");
        }
    }

    public function testUuidSecure(): void
    {
        $uuid = uuidSecure();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
    }

    public function testUserCreateRandomPassword(): void
    {
        $password = UserCreateRandomPassword();
        $this->assertSame(12, strlen($password));
        $this->assertMatchesRegularExpression('/^[abcdefghijkmnopqrstuvwxyz023456789]+$/', $password);
    }

    // --- Session-check function tests ---

    public function testIsSuperAdminWhenSet(): void
    {
        $this->assertTrue(isSuperAdmin());
    }

    public function testIsSuperAdminWhenNotSet(): void
    {
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(isSuperAdmin());
    }

    public function testIsSeasonAdminViaSuperadmin(): void
    {
        $this->assertTrue(isSeasonAdmin('HRN2026'));
    }

    public function testIsSeasonAdminViaSeasonRole(): void
    {
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 999;
        $this->assertTrue(isSeasonAdmin('HRN2026'));
        $this->assertFalse(isSeasonAdmin('OTHER'));
    }

    public function testIsSpiritAdminViaSuperadmin(): void
    {
        $this->assertTrue(isSpiritAdmin('HRN2026'));
    }

    public function testIsSpiritAdminViaSpiritRole(): void
    {
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['spiritadmin']['HRN2026'] = 998;
        $this->assertTrue(isSpiritAdmin('HRN2026'));
        $this->assertFalse(isSpiritAdmin('OTHER'));
    }

    public function testIsPlayerAdmin(): void
    {
        $_SESSION['userproperties']['userrole']['playeradmin'][42] = 997;
        $this->assertTrue(isPlayerAdmin(42));
        $this->assertFalse(isPlayerAdmin(99));
    }

    public function testHasPlayerAdminRights(): void
    {
        $this->assertFalse(hasPlayerAdminRights());
        $_SESSION['userproperties']['userrole']['playeradmin'] = [42 => 997];
        $this->assertTrue(hasPlayerAdminRights());
    }

    public function testIsLoggedIn(): void
    {
        $this->assertTrue(isLoggedIn());
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(isLoggedIn());
        unset($_SESSION['uid']);
        $this->assertFalse(isLoggedIn());
    }

    public function testHasScheduleRights(): void
    {
        $this->assertFalse(hasScheduleRights());
        $_SESSION['userproperties']['userrole']['resadmin'] = true;
        $this->assertTrue(hasScheduleRights());
    }

    public function testHasViewAndEditUsersRight(): void
    {
        $this->assertTrue(hasViewUsersRight());
        $this->assertTrue(hasEditUsersRight());
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasViewUsersRight());
        $this->assertFalse(hasEditUsersRight());
    }

    public function testHasChangeCurrentSeasonRight(): void
    {
        $this->assertTrue(hasChangeCurrentSeasonRight());
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasChangeCurrentSeasonRight());
    }

    public function testCanBypassEventReadonly(): void
    {
        $this->assertTrue(canBypassEventReadonly('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(canBypassEventReadonly('HRN2026'));
    }

    public function testHasTranslationRight(): void
    {
        $this->assertTrue(hasTranslationRight());
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasTranslationRight());
    }

    public function testHasAddMediaRight(): void
    {
        $this->assertTrue(hasAddMediaRight());
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(hasAddMediaRight());
    }

    public function testHasSpiritToolsRight(): void
    {
        $this->assertTrue(hasSpiritToolsRight('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasSpiritToolsRight('HRN2026'));
        $_SESSION['userproperties']['userrole']['spiritadmin']['HRN2026'] = 1;
        $this->assertTrue(hasSpiritToolsRight('HRN2026'));
    }

    public function testHasSpiritEditRight(): void
    {
        $this->assertTrue(hasSpiritEditRight('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasSpiritEditRight('HRN2026'));
    }

    public function testHasSeasonSeriesPageAccess(): void
    {
        $this->assertTrue(hasSeasonSeriesPageAccess('HRN2026', 100));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasSeasonSeriesPageAccess('HRN2026', 100));
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        $this->assertTrue(hasSeasonSeriesPageAccess('HRN2026', 100));
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        $this->assertTrue(hasSeasonSeriesPageAccess('HRN2026', 100));
    }

    public function testHasReservationsPageAccess(): void
    {
        $this->assertTrue(hasReservationsPageAccess());
        $this->assertTrue(hasReservationsPageAccess('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasReservationsPageAccess());
        $this->assertFalse(hasReservationsPageAccess('HRN2026'));
    }

    public function testHasAccreditationPageAccess(): void
    {
        $this->assertTrue(hasAccreditationPageAccess('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasAccreditationPageAccess('HRN2026'));
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        $this->assertTrue(hasAccreditationPageAccess('HRN2026'));
    }

    public function testHasAccredidationRight(): void
    {
        // Team 300 in series 100, season HRN2026 (not readonly)
        $this->assertTrue(hasAccredidationRight(300));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasAccredidationRight(300));
        $_SESSION['userproperties']['userrole']['accradmin'][300] = 1;
        $this->assertTrue(hasAccredidationRight(300));
    }

    public function testHasEditSeasonSeriesRight(): void
    {
        $this->assertTrue(hasEditSeasonSeriesRight('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditSeasonSeriesRight('HRN2026'));
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        $this->assertTrue(hasEditSeasonSeriesRight('HRN2026'));
    }

    public function testHasEditPlacesRight(): void
    {
        $this->assertTrue(hasEditPlacesRight('HRN2026'));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditPlacesRight('HRN2026'));
    }

    public function testHasEditTeamsRight(): void
    {
        $this->assertTrue(hasEditTeamsRight(100));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditTeamsRight(100));
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        $this->assertTrue(hasEditTeamsRight(100));
    }

    public function testHasEditGamesRight(): void
    {
        $this->assertTrue(hasEditGamesRight(100));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditGamesRight(100));
    }

    public function testHasEditPlayersRight(): void
    {
        // Team 300 in series 100, season HRN2026
        $this->assertTrue(hasEditPlayersRight(300));
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditPlayersRight(300));
    }

    public function testHasCurrentSeasonsEditRight(): void
    {
        $this->assertTrue(hasCurrentSeasonsEditRight());
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasCurrentSeasonsEditRight());
    }

    // --- DB read tests ---

    public function testSetUserSessionDataSimpleValue(): void
    {
        SetUserSessionData('admin');
        $this->assertSame('admin', $_SESSION['uid']);
        $this->assertArrayHasKey('superadmin', $_SESSION['userproperties']['userrole']);
    }

    public function testSetUserSessionDataCompoundValue(): void
    {
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'seasonadmin:HRN2026')");
        $propId = (int) DBQueryToValue("SELECT prop_id FROM uo_userproperties WHERE userid='admin' AND value='seasonadmin:HRN2026'");
        try {
            SetUserSessionData('admin');
            $this->assertArrayHasKey('seasonadmin', $_SESSION['userproperties']['userrole']);
            $this->assertArrayHasKey('HRN2026', $_SESSION['userproperties']['userrole']['seasonadmin']);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE prop_id=$propId");
        }
    }

    public function testUserExists(): void
    {
        $this->assertTrue(UserExists('admin'));
        $this->assertFalse(UserExists('nonexistent_user_xyz'));
    }

    public function testUserInfo(): void
    {
        $info = UserInfo('admin');
        $this->assertSame('admin', $info['userid']);
        $this->assertSame('Harness Admin', $info['name']);
        $this->assertSame('admin@example.com', $info['email']);
    }

    public function testUserIdForMail(): void
    {
        $this->assertSame('admin', UserIdForMail('admin@example.com'));
        $this->assertNull(UserIdForMail('nobody@example.com'));
    }

    public function testIsRegistered(): void
    {
        $this->assertFalse(IsRegistered('anonymous'));
        $this->assertTrue(IsRegistered('admin'));
        $this->assertFalse(IsRegistered('nonexistent_xyz'));
    }

    public function testIsRegisteredFromRegisterRequest(): void
    {
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token) VALUES ('pending_user', 'hash', 'Pending', 'pending@example.com', 'tok_pending_1')");
        try {
            $this->assertTrue(IsRegistered('pending_user'));
        } finally {
            DBQuery("DELETE FROM uo_registerrequest WHERE userid='pending_user'");
        }
    }

    public function testEmailUsed(): void
    {
        $this->assertTrue(emailUsed('admin@example.com'));
        $this->assertFalse(emailUsed('nobody@nowhere.com'));
    }

    public function testGetUserpropertyArray(): void
    {
        $roles = getUserpropertyArray('admin', 'userrole');
        $this->assertArrayHasKey('superadmin', $roles);
    }

    public function testGetPropId(): void
    {
        $propId = getPropId('admin', 'userrole', 'superadmin');
        $this->assertNotEmpty($propId);
        $this->assertNull(getPropId('admin', 'userrole', 'nonexistent'));
    }

    public function testGetUserroles(): void
    {
        $roles = getUserroles('admin');
        $this->assertArrayHasKey('superadmin', $roles);
    }

    public function testGetPoolselectors(): void
    {
        $selectors = getPoolselectors('admin');
        $this->assertIsArray($selectors);
    }

    public function testGetUserLocale(): void
    {
        // Insert locale to test the normal return path (GetDefaultLocale() is not loaded in this profile)
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'locale', 'fi_FI')");
        try {
            $this->assertSame('fi_FI', getUserLocale('admin'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='locale'");
        }
    }

    public function testGetSeriesName(): void
    {
        $this->assertSame('Open', getSeriesName(100));
        $this->assertSame('', getSeriesName(9999));
    }

    public function testGetTeamSeries(): void
    {
        $this->assertSame('100', getTeamSeries(300));
        $this->assertSame('', getTeamSeries(9999));
    }

    public function testGetTeamSeason(): void
    {
        $this->assertSame('HRN2026', getTeamSeason(300));
        $this->assertSame('', getTeamSeason(9999));
    }

    public function testGetTeamName(): void
    {
        $this->assertSame('Helsinki Heat', getTeamName(300));
        $this->assertSame('', getTeamName(0));
        $this->assertSame('', getTeamName(9999));
    }

    public function testGetViewPools(): void
    {
        $_SESSION['userproperties']['poolselector']['currentseason'] = 1;
        $pools = getViewPools('HRN2026');
        $this->assertIsArray($pools);
        $this->assertNotEmpty($pools);
    }

    public function testSetSelectedSeason(): void
    {
        $_GET['selseason'] = 'HRN2026';
        setSelectedSeason();
        $this->assertSame('HRN2026', $_SESSION['userproperties']['selseason']);
        unset($_GET['selseason']);
    }

    public function testIsSuperAdminByUserid(): void
    {
        $this->assertTrue(isSuperAdminByUserid('admin'));
    }

    public function testIsSuperAdminByUseridFalse(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_nosup', 'hash', 'No Sup')");
        try {
            $this->assertFalse(isSuperAdminByUserid('testuser_nosup'));
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_nosup'");
        }
    }

    // --- DB write tests ---

    public function testAddAndRemoveUserRole(): void
    {
        $result = AddUserRole('admin', 'resadmin');
        $this->assertTrue($result);
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='admin' AND name='userrole' AND value='resadmin'");
        $this->assertSame(1, $count);
        $this->assertFalse(AddUserRole('admin', 'resadmin'));
        $propId = (int) getPropId('admin', 'userrole', 'resadmin');
        $this->assertTrue(RemoveUserRole('admin', $propId));
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='admin' AND name='userrole' AND value='resadmin'");
        $this->assertSame(0, $count);
    }

    public function testAddAndRemoveEditSeason(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_editseas', 'hash', 'Edit Seas')");
        try {
            AddEditSeason('testuser_editseas', 'HRN2026');
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_editseas' AND name='editseason' AND value='HRN2026'");
            $this->assertSame(1, $count);
            AddEditSeason('testuser_editseas', 'HRN2026');
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_editseas' AND name='editseason' AND value='HRN2026'");
            $this->assertSame(1, $count);
            $propId = (int) DBQueryToValue("SELECT prop_id FROM uo_userproperties WHERE userid='testuser_editseas' AND name='editseason' AND value='HRN2026'");
            RemoveEditSeason('testuser_editseas', $propId);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_editseas' AND name='editseason' AND value='HRN2026'");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_editseas'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_editseas'");
        }
    }

    public function testGetEditSeasons(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_getseas', 'hash', 'Get Seas')");
        try {
            AddEditSeason('testuser_getseas', 'HRN2026');
            $seasons = getEditSeasons('testuser_getseas');
            $this->assertArrayHasKey('HRN2026', $seasons);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_getseas'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_getseas'");
        }
    }

    public function testSortEditSeasonsEmpty(): void
    {
        $this->assertSame([], SortEditSeasons([]));
    }

    public function testAddAndRemovePoolSelector(): void
    {
        AddPoolSelector('admin', 'currentseason');
        $propId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND name='poolselector' AND value='currentseason'");
        $this->assertGreaterThan(0, $propId);
        $this->assertTrue(RemovePoolSelector('admin', $propId));
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE prop_id=$propId");
        $this->assertSame(0, $count);
    }

    public function testAddSeasonUserRoleAndRemove(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_srole', 'hash', 'Season Role')");
        try {
            $result = AddSeasonUserRole('testuser_srole', 'seasonadmin:HRN2026', 'HRN2026');
            $this->assertTrue($result);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_srole' AND name='userrole' AND value='seasonadmin:HRN2026'");
            $this->assertSame(1, $count);
            $countEd = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_srole' AND name='editseason' AND value='HRN2026'");
            $this->assertSame(1, $countEd);
            $this->assertFalse(AddSeasonUserRole('testuser_srole', 'seasonadmin:HRN2026', 'HRN2026'));
            RemoveSeasonUserRole('testuser_srole', 'seasonadmin:HRN2026', 'HRN2026');
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_srole' AND name='userrole' AND value='seasonadmin:HRN2026'");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_srole'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_srole'");
        }
    }

    public function testUserHasSeasonScopedRoleViaSeasonAdmin(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_scoped', 'hash', 'Scoped')");
        try {
            $this->assertFalse(UserHasSeasonScopedRole('testuser_scoped', 'HRN2026'));
            DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_scoped', 'userrole', 'seasonadmin:HRN2026')");
            $this->assertTrue(UserHasSeasonScopedRole('testuser_scoped', 'HRN2026'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_scoped'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_scoped'");
        }
    }

    public function testUserHasSeasonScopedRoleViaSeriesAdmin(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_sera', 'hash', 'SerAdmin')");
        try {
            DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_sera', 'userrole', 'seriesadmin:100')");
            $this->assertTrue(UserHasSeasonScopedRole('testuser_sera', 'HRN2026'));
            $this->assertFalse(UserHasSeasonScopedRole('testuser_sera', 'OTHER'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_sera'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_sera'");
        }
    }

    public function testUserHasSeasonScopedRoleViaTeamAdmin(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_ta', 'hash', 'TeamAdmin')");
        try {
            // Team 300 is in season HRN2026
            DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_ta', 'userrole', 'teamadmin:300')");
            $this->assertTrue(UserHasSeasonScopedRole('testuser_ta', 'HRN2026'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_ta'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_ta'");
        }
    }

    public function testSetSuperAdmin(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_sa', 'hash', 'Super')");
        try {
            $this->assertFalse(isSuperAdminByUserid('testuser_sa'));
            setSuperAdmin('testuser_sa', true);
            $this->assertTrue(isSuperAdminByUserid('testuser_sa'));
            // Setting again should not duplicate
            setSuperAdmin('testuser_sa', true);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_sa' AND name='userrole' AND value='superadmin'");
            $this->assertSame(1, $count);
            setSuperAdmin('testuser_sa', false);
            $this->assertFalse(isSuperAdminByUserid('testuser_sa'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_sa'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_sa'");
        }
    }

    public function testGetTeamAdmins(): void
    {
        $admins = GetTeamAdmins(300);
        $this->assertIsArray($admins);
    }

    public function testUserExtraEmails(): void
    {
        $this->assertFalse(UserExtraEmails('admin'));
    }

    public function testRemoveExtraEmail(): void
    {
        DBQuery("INSERT INTO uo_extraemail (userid, email) VALUES ('admin', 'extra@example.com')");
        try {
            $emails = UserExtraEmails('admin');
            $this->assertIsArray($emails);
            RemoveExtraEmail('admin', 'extra@example.com');
            $this->assertFalse(UserExtraEmails('admin'));
        } finally {
            DBQuery("DELETE FROM uo_extraemail WHERE userid='admin' AND email='extra@example.com'");
        }
    }

    public function testToPrimaryEmail(): void
    {
        DBQuery("INSERT INTO uo_extraemail (userid, email) VALUES ('admin', 'swap@example.com')");
        try {
            ToPrimaryEmail('admin', 'swap@example.com');
            $info = UserInfo('admin');
            $this->assertSame('swap@example.com', $info['email']);
            $this->assertNotFalse(UserExtraEmails('admin'));
        } finally {
            DBQuery("UPDATE uo_users SET email='admin@example.com' WHERE userid='admin'");
            DBQuery("DELETE FROM uo_extraemail WHERE userid='admin'");
        }
    }

    public function testSetUserLocale(): void
    {
        $GLOBALS['locales'] = ['en_US' => 'English US'];
        try {
            SetUserLocale('admin', 'en_US');
            $this->assertSame('en_US', getUserLocale('admin'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='locale'");
        }
    }

    public function testSetUserLocaleDuplicateUpdates(): void
    {
        $GLOBALS['locales'] = ['en_US' => 'English US', 'fi_FI' => 'Finnish'];
        try {
            SetUserLocale('admin', 'en_US');
            SetUserLocale('admin', 'fi_FI');
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='admin' AND name='locale'");
            $this->assertSame(1, $count);
            $this->assertSame('fi_FI', getUserLocale('admin'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='locale'");
        }
    }

    public function testCreateUserAccountAndDeleteUser(): void
    {
        $result = CreateUserAccount('testuser_create', 'password123', 'Test User', 'testcreate@example.com');
        $this->assertTrue($result);
        $this->assertTrue(UserExists('testuser_create'));
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_create' AND name='poolselector'");
        $this->assertSame(1, $count);
        $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='testuser_create'");
        $this->assertTrue(verifyUserPassword('password123', $row['password']));
        DeleteUser('testuser_create');
        $this->assertFalse(UserExists('testuser_create'));
    }

    public function testCreateConfirmedUserWithNoEmail(): void
    {
        $hash = hashUserPassword('mypass');
        $result = CreateConfirmedUser('testuser_confirmed', $hash, 'Confirmed User', '');
        $this->assertTrue($result);
        try {
            $this->assertTrue(UserExists('testuser_confirmed'));
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_confirmed' AND name='poolselector'");
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_confirmed'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_confirmed'");
        }
    }

    public function testUserUpdateInfo(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name, email) VALUES ('testuser_upd', 'hash', 'Old Name', 'upd@example.com')");
        try {
            $rowBefore = DBQueryToRow("SELECT id FROM uo_users WHERE userid='testuser_upd'");
            UserUpdateInfo((int) $rowBefore['id'], 'testuser_upd', 'testuser_upd2', 'New Name');
            $this->assertFalse(UserExists('testuser_upd'));
            $this->assertTrue(UserExists('testuser_upd2'));
            $info = DBQueryToRow("SELECT name FROM uo_users WHERE userid='testuser_upd2'");
            $this->assertSame('New Name', $info['name']);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid IN ('testuser_upd', 'testuser_upd2')");
            DBQuery("DELETE FROM uo_users WHERE userid IN ('testuser_upd', 'testuser_upd2')");
        }
    }

    public function testUserChangePassword(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_chpw', 'oldhash', 'ChPw')");
        try {
            UserChangePassword('testuser_chpw', 'newpassword');
            $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='testuser_chpw'");
            $this->assertTrue(verifyUserPassword('newpassword', $row['password']));
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_chpw'");
        }
    }

    public function testUpdateUserPasswordHash(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_uphash', 'oldhash', 'UpHash')");
        try {
            updateUserPasswordHash('testuser_uphash', 'newpassword');
            $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='testuser_uphash'");
            $this->assertTrue(verifyUserPassword('newpassword', $row['password']));
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_uphash'");
        }
    }

    public function testPasswordResetFlow(): void
    {
        $token = 'test-reset-token-999';
        DBQuery("INSERT INTO uo_passwordresetrequest (userid, token) VALUES ('admin', '$token')");
        try {
            $this->assertSame('admin', PasswordResetUIDByToken($token));
            $this->assertTrue(ConfirmPasswordReset($token, 'resetpassword'));
            $this->assertFalse(PasswordResetUIDByToken($token));
            $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='admin'");
            $this->assertTrue(verifyUserPassword('resetpassword', $row['password']));
        } finally {
            updateUserPasswordHash('admin', 'harness-admin');
            DBQuery("DELETE FROM uo_passwordresetrequest WHERE userid='admin'");
        }
    }

    public function testPasswordResetUIDByTokenNotFound(): void
    {
        $this->assertFalse(PasswordResetUIDByToken('nonexistent-token-999'));
    }

    public function testConfirmPasswordResetInvalidToken(): void
    {
        $this->assertFalse(ConfirmPasswordReset('no-such-token', 'newpass'));
    }

    public function testRegisterUIDByToken(): void
    {
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token) VALUES ('regtest_user', 'hash', 'Reg Test', 'reg@example.com', 'reg-tok-123')");
        try {
            $this->assertSame('regtest_user', RegisterUIDByToken('reg-tok-123'));
            $this->assertFalse(RegisterUIDByToken('bad-token'));
        } finally {
            DBQuery("DELETE FROM uo_registerrequest WHERE userid='regtest_user'");
        }
    }

    public function testConfirmRegister(): void
    {
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token) VALUES ('regtest_reg', 'hash', 'Reg Test', 'regreg@example.com', 'tok-reg-123')");
        try {
            $this->assertTrue(ConfirmRegister('tok-reg-123'));
            $this->assertTrue(UserExists('regtest_reg'));
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_registerrequest WHERE token='tok-reg-123'");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='regtest_reg'");
            DBQuery("DELETE FROM uo_users WHERE userid='regtest_reg'");
            DBQuery("DELETE FROM uo_registerrequest WHERE userid='regtest_reg'");
        }
    }

    public function testConfirmRegisterInvalidToken(): void
    {
        $this->assertFalse(ConfirmRegister('nonexistent-register-token'));
    }

    public function testConfirmRegisterUID(): void
    {
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token) VALUES ('regtest_confirm', 'hash', 'Reg Confirm', 'regconfirm@example.com', 'tok-confirm-123')");
        try {
            $this->assertTrue(ConfirmRegisterUID('regtest_confirm'));
            $this->assertTrue(UserExists('regtest_confirm'));
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_registerrequest WHERE userid='regtest_confirm'");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='regtest_confirm'");
            DBQuery("DELETE FROM uo_users WHERE userid='regtest_confirm'");
            DBQuery("DELETE FROM uo_registerrequest WHERE userid='regtest_confirm'");
        }
    }

    public function testDeleteRegisterRequest(): void
    {
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token) VALUES ('delreg_user', 'hash', 'Del Reg', 'delreg@example.com', 'tok-delreg')");
        DeleteRegisterRequest('delreg_user');
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_registerrequest WHERE userid='delreg_user'");
        $this->assertSame(0, $count);
    }

    public function testConfirmEmail(): void
    {
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_cemail', 'hash', 'CEmail')");
        DBQuery("INSERT INTO uo_extraemailrequest (userid, email, token) VALUES ('testuser_cemail', 'confirmed@example.com', 'tok-email-confirm')");
        try {
            $this->assertTrue(ConfirmEmail('tok-email-confirm'));
            $this->assertIsArray(UserExtraEmails('testuser_cemail'));
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_extraemailrequest WHERE token='tok-email-confirm'");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_extraemail WHERE userid='testuser_cemail'");
            DBQuery("DELETE FROM uo_extraemailrequest WHERE userid='testuser_cemail'");
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_cemail'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_cemail'");
        }
    }

    public function testConfirmEmailNotFound(): void
    {
        $this->assertFalse(ConfirmEmail('nonexistent-email-token'));
    }

    public function testUserListRightsHtml(): void
    {
        $html = UserListRightsHtml('admin');
        $this->assertStringContainsString('superadmin', $html);
    }

    public function testUserListRightsHtmlWithSeasonAdmin(): void
    {
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'seasonadmin:HRN2026')");
        $propId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND value='seasonadmin:HRN2026'");
        try {
            $html = UserListRightsHtml('admin');
            $this->assertStringContainsString('seasonadmin', $html);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE prop_id=$propId");
        }
    }

    public function testUserListRightsHtmlWithTeamAdmin(): void
    {
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'teamadmin:300')");
        $propId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND value='teamadmin:300'");
        try {
            $html = UserListRightsHtml('admin');
            $this->assertStringContainsString('teamadmin', $html);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE prop_id=$propId");
        }
    }

    public function testCreateNewUsername(): void
    {
        $name = CreateNewUsername('Zack', 'Zebra', 'zzebra@example.com');
        $this->assertNotEmpty($name);
        $this->assertMatchesRegularExpression('/^z/', $name);
    }

    public function testTeamResponsibilities(): void
    {
        // Superadmin has no explicit teamadmin role, returns empty array
        $result = TeamResponsibilities('admin', 'HRN2026');
        $this->assertIsArray($result);
    }

    public function testGameResponsibilitiesAsSuperadmin(): void
    {
        $result = GameResponsibilities('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGameResponsibilitiesEmptyWhenNoCriteria(): void
    {
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $result = GameResponsibilities('HRN2026');
        $this->assertSame([], $result);
    }

    public function testGameResponsibilityArray(): void
    {
        $result = GameResponsibilityArray('HRN2026');
        $this->assertIsArray($result);
    }

    // --- Additional coverage tests ---

    public function testUserUpdateInfoSameUser(): void
    {
        // Update admin's own record (same userid) — triggers SetUserSessionData at line 241
        $row = DBQueryToRow("SELECT id FROM uo_users WHERE userid='admin'");
        $origName = DBQueryToValue("SELECT name FROM uo_users WHERE userid='admin'");
        try {
            UserUpdateInfo((int) $row['id'], 'admin', 'admin', 'Harness Admin Updated');
            $this->assertSame('admin', $_SESSION['uid']);
            $info = UserInfo('admin');
            $this->assertSame('Harness Admin Updated', $info['name']);
        } finally {
            DBQuery("UPDATE uo_users SET name='" . DBEscapeString($origName) . "' WHERE userid='admin'");
        }
    }

    public function testCompoundValueHandlingDuplicatePrefix(): void
    {
        // Two rows with the same compound prefix (seasonadmin:*) cover the
        // "key already exists" branch in both SetUserSessionData (lines 284-286)
        // and getUserpropertyArray (lines 410-412).
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, iscurrent) VALUES ('XTST', 'X Test', '2025-01-01', 0)");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'seasonadmin:HRN2026')");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'seasonadmin:XTST')");
        try {
            // covers SetUserSessionData lines 284-286
            SetUserSessionData('admin');
            $this->assertArrayHasKey('HRN2026', $_SESSION['userproperties']['userrole']['seasonadmin']);
            $this->assertArrayHasKey('XTST', $_SESSION['userproperties']['userrole']['seasonadmin']);

            // covers getUserpropertyArray lines 410-412
            $roles = getUserroles('admin');
            $this->assertArrayHasKey('seasonadmin', $roles);
            $this->assertArrayHasKey('HRN2026', $roles['seasonadmin']);
            $this->assertArrayHasKey('XTST', $roles['seasonadmin']);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='userrole' AND value IN ('seasonadmin:HRN2026','seasonadmin:XTST')");
            DBQuery("DELETE FROM uo_season WHERE season_id='XTST'");
        }
    }

    public function testSortEditSeasonsMultiple(): void
    {
        // Passing two seasons covers the ", '" separator branch at line 311.
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, iscurrent) VALUES ('S2025', 'Season 2025', '2025-01-01', 0)");
        try {
            $sorted = SortEditSeasons(['HRN2026' => 1, 'S2025' => 2]);
            $this->assertArrayHasKey('HRN2026', $sorted);
            $this->assertArrayHasKey('S2025', $sorted);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='S2025'");
        }
    }

    public function testGetViewPoolsWithMultipleSelectors(): void
    {
        // Two selectors trigger the OR prefix at line 448; each branch (team/season/series/pool)
        // covers lines 453-460.
        $_SESSION['userproperties']['poolselector'] = [
            'currentseason' => 1,
            'team'          => [300 => 2],
            'season'        => ['HRN2026' => 3],
            'series'        => [100 => 4],
            'pool'          => [200 => 5],
        ];
        $pools = getViewPools('HRN2026');
        $this->assertIsArray($pools);
    }

    public function testReadonlySeasonBlocksEditing(): void
    {
        // event_readonly=1 with a non-superadmin seasonadmin blocks all edit rights
        // (lines 566, 651, 664, 679, 694, 735, 786).
        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        // SeasonInfo is request-cached; flush it so the DB change is visible.
        CacheForgetNamespace('season_info');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        $_SESSION['userproperties']['userrole']['spiritadmin']['HRN2026'] = 2;
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 3;
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 4;
        try {
            $this->assertFalse(hasSpiritEditRight('HRN2026'));
            $this->assertFalse(hasEditSeasonSeriesRight('HRN2026'));
            $this->assertFalse(hasEditPlacesRight('HRN2026'));
            $this->assertFalse(hasEditTeamsRight(100));
            $this->assertFalse(hasEditGamesRight(100));
            $this->assertFalse(hasEditPlayersRight(300));
            $this->assertFalse(hasAccredidationRight(300));
        } finally {
            DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
            CacheForgetNamespace('season_info');
        }
    }

    public function testHasAccreditationPageAccessSeriesAdmin(): void
    {
        // seriesadmin for a series inside HRN2026 — covers line 586
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        $this->assertTrue(hasAccreditationPageAccess('HRN2026'));
    }

    public function testHasAccreditationPageAccessAccrAdmin(): void
    {
        // accradmin for a team inside HRN2026 — covers line 592
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['accradmin'][300] = 1;
        $this->assertTrue(hasAccreditationPageAccess('HRN2026'));
    }

    public function testUserListRightsHtmlWithSpiritAdmin(): void
    {
        // spiritadmin case in the switch statement — covers lines 823-827
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('admin', 'userrole', 'spiritadmin:HRN2026')");
        $propId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND value='spiritadmin:HRN2026'");
        try {
            $html = UserListRightsHtml('admin');
            $this->assertStringContainsString('spiritadmin', $html);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE prop_id=$propId");
        }
    }

    public function testUserHasSeasonScopedRoleNonMatchingSeasonAdmin(): void
    {
        // seasonadmin for a *different* season — covers the break at line 1163
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, iscurrent) VALUES ('NOMS', 'No Match', '2024-01-01', 0)");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_nsco', 'hash', 'NScope')");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_nsco', 'userrole', 'seasonadmin:NOMS')");
        try {
            $this->assertFalse(UserHasSeasonScopedRole('testuser_nsco', 'HRN2026'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_nsco'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_nsco'");
            DBQuery("DELETE FROM uo_season WHERE season_id='NOMS'");
        }
    }

    public function testUserHasSeasonScopedRoleViaAccrAdmin(): void
    {
        // accradmin for team 300 (in HRN2026) — covers lines 1170-1174
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_acscoped', 'hash', 'AccScoped')");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_acscoped', 'userrole', 'accradmin:300')");
        try {
            $this->assertTrue(UserHasSeasonScopedRole('testuser_acscoped', 'HRN2026'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_acscoped'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_acscoped'");
        }
    }

    public function testUserHasSeasonScopedRoleViaResGameAdmin(): void
    {
        // resgameadmin for reservation 500 — ReservationSeasons(500) returns ['HRN2026']
        // covers lines 1180-1188
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_rgscoped', 'hash', 'RGScoped')");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('testuser_rgscoped', 'userrole', 'resgameadmin:500')");
        try {
            $this->assertTrue(UserHasSeasonScopedRole('testuser_rgscoped', 'HRN2026'));
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_rgscoped'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_rgscoped'");
        }
    }

    public function testFinalizeNewUserWithPlayerProfile(): void
    {
        // uo_player_profile row with matching email causes FinalizeNewUser to insert
        // a playeradmin role — covers lines 1475-1481
        DBQuery("INSERT INTO uo_player_profile (email) VALUES ('finalize@example.com')");
        $profileId = (int) DBQueryToValue("SELECT profile_id FROM uo_player_profile WHERE email='finalize@example.com'");
        try {
            $result = CreateConfirmedUser('testuser_finalize', hashUserPassword('pass'), 'Finalize User', 'finalize@example.com');
            $this->assertTrue($result);
            $count = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_finalize' AND name='userrole' AND value='playeradmin:$profileId'"
            );
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_finalize'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_finalize'");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
        }
    }

    public function testConfirmEmailWithPlayerProfile(): void
    {
        // uo_player_profile row with matching email causes ConfirmEmail to insert
        // a playeradmin role — covers lines 1514-1520
        DBQuery("INSERT INTO uo_player_profile (email) VALUES ('ceprofile@example.com')");
        $profileId = (int) DBQueryToValue("SELECT profile_id FROM uo_player_profile WHERE email='ceprofile@example.com'");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_ceprof', 'hash', 'CEProf')");
        DBQuery("INSERT INTO uo_extraemailrequest (userid, email, token) VALUES ('testuser_ceprof', 'ceprofile@example.com', 'tok-cep-999')");
        try {
            $this->assertTrue(ConfirmEmail('tok-cep-999'));
            $count = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_userproperties WHERE userid='testuser_ceprof' AND name='userrole' AND value='playeradmin:$profileId'"
            );
            $this->assertSame(1, $count);
        } finally {
            DBQuery("DELETE FROM uo_userproperties WHERE userid='testuser_ceprof'");
            DBQuery("DELETE FROM uo_extraemail WHERE userid='testuser_ceprof'");
            DBQuery("DELETE FROM uo_extraemailrequest WHERE userid='testuser_ceprof'");
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_ceprof'");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
        }
    }

    public function testTeamResponsibilitiesWithTeamAdmin(): void
    {
        // teamadmin role set for team 300 — covers line 1587
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        $result = TeamResponsibilities('admin', 'HRN2026');
        // DB returns team_id as string; cast to compare
        $result = array_map('intval', $result);
        $this->assertContains(300, $result);
    }

    public function testGameResponsibilitiesWithSeriesAdmin(): void
    {
        // seriesadmin[100] — covers lines 1613-1622 (series criteria branch)
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        $result = GameResponsibilities('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGameResponsibilitiesWithTeamAdmin(): void
    {
        // teamadmin[300] — covers lines 1629-1637 (team criteria branch)
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        $result = GameResponsibilities('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGameResponsibilitiesWithResGameAdmin(): void
    {
        // resgameadmin[500] — ReservationSeasons(500)=['HRN2026'], covers lines 1655-1671
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['resgameadmin'][500] = 1;
        $result = GameResponsibilities('HRN2026');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGameResponsibilityArrayEmpty(): void
    {
        // No criteria means GameResponsibilities returns [] (falsy),
        // so GameResponsibilityArray returns early at line 1691.
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $result = GameResponsibilityArray('HRN2026');
        $this->assertSame([], $result);
    }

    public function testCreateNewUsernameWithCollisions(): void
    {
        // Pre-create the first three candidates to force the while-loop — covers lines 1843-1853
        // firstname='Test', lastname='User', email='tu@example.com'
        // -> try='tuser', emailStart='tu', firstname.lastname='test.user'
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tuser', 'hash', 'T User')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tu', 'hash', 'TU')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('test.user', 'hash', 'Test User')");
        try {
            $name = CreateNewUsername('Test', 'User', 'tu@example.com');
            // First uncollided candidate in the while loop is 'tuser1'
            $this->assertSame('tuser1', $name);
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid IN ('tuser', 'tu', 'test.user')");
        }
    }

    public function testHasEditPlayerProfileRight(): void
    {
        // Load player.functions.php so PlayerInfo() is available — covers lines 701-720
        LegacyApp::requireTopLevelLib('player.functions.php');

        // Player 800 is in team 300, series 100, season HRN2026
        $this->assertTrue(hasEditPlayerProfileRight(800));

        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $this->assertFalse(hasEditPlayerProfileRight(800));

        // Non-existent player returns false via the early exit
        $this->assertFalse(hasEditPlayerProfileRight(99999));
    }

    public function testHasEditPlayerProfileRightReadonly(): void
    {
        // teamadmin + readonly season -> false at line 718
        LegacyApp::requireTopLevelLib('player.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        CacheForgetNamespace('season_info');
        try {
            $this->assertFalse(hasEditPlayerProfileRight(800));
        } finally {
            DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
            CacheForgetNamespace('season_info');
        }
    }

    public function testClearUserSessionData(): void
    {
        // Covers destroySessionCompletely + startSecureSession + SetUserSessionData("anonymous")
        // lines 484-486
        ClearUserSessionData();
        $this->assertSame('anonymous', $_SESSION['uid']);
    }

    public function testVerifyUserPasswordBcryptRehash(): void
    {
        // A low-cost bcrypt hash triggers password_needs_rehash -> updateUserPasswordHash
        // covers line 59
        $oldHash = password_hash('pass123', PASSWORD_BCRYPT, ['cost' => 4]);
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('testuser_rebc', '$oldHash', 'ReBc')");
        try {
            $this->assertTrue(verifyUserPassword('pass123', $oldHash, 'testuser_rebc'));
            $row = DBQueryToRow("SELECT password FROM uo_users WHERE userid='testuser_rebc'");
            $this->assertFalse(isLegacyMd5Hash($row['password']));
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='testuser_rebc'");
        }
    }

    public function testCreateNewUsernameEmailStartFallback(): void
    {
        // 'tuser2' pre-created; emailStart 'te' is free -> returns 'te' (line 1844)
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tuser2', 'hash', 'T User2')");
        try {
            $name = CreateNewUsername('Test', 'User2', 'te@example.com');
            $this->assertSame('te', $name);
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='tuser2'");
        }
    }

    public function testCreateNewUsernameDotNameFallback(): void
    {
        // Both 'tuser3' and 'te2' pre-created; 'test.user3' is free -> returns it (line 1847)
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tuser3', 'hash', 'T3')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('te2', 'hash', 'TE2')");
        try {
            $name = CreateNewUsername('Test', 'User3', 'te2@example.com');
            $this->assertSame('test.user3', $name);
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid IN ('tuser3', 'te2')");
        }
    }

    public function testCreateNewUsernameWhileEmailFallback(): void
    {
        // All base candidates blocked; 'tuser4' + 'te3' + 'test.user4' + 'tuser41' blocked
        // -> while loop tries emailStart+extra: 'te31' is free -> returns it (lines 1855-1856)
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tuser4', 'hash', 'T4')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('te3', 'hash', 'TE3')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('test.user4', 'hash', 'TU4')");
        DBQuery("INSERT INTO uo_users (userid, password, name) VALUES ('tuser41', 'hash', 'TU41')");
        try {
            $name = CreateNewUsername('Test', 'User4', 'te3@example.com');
            $this->assertSame('te31', $name);
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid IN ('tuser4','te3','test.user4','tuser41')");
        }
    }

    public function testSelfRoleManagementRefreshesSession(): void
    {
        // Calling role management functions for the current user ('admin') triggers
        // SetUserSessionData for self — covers lines 997, 1028, 1048, 1077, 1107, 1137.

        // AddEditSeason for own user (line 1028)
        AddEditSeason('admin', 'HRN2026');
        $this->assertArrayHasKey('HRN2026', $_SESSION['userproperties']['editseason'] ?? $_SESSION['userproperties']);
        $propId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND name='editseason' AND value='HRN2026'");

        // RemoveEditSeason for own user (line 997)
        RemoveEditSeason('admin', $propId);

        // AddUserRole for own user (line 1077)
        AddUserRole('admin', 'testtestrole');
        $rolePropId = (int) DBQueryToValue("SELECT MAX(prop_id) FROM uo_userproperties WHERE userid='admin' AND name='userrole' AND value='testtestrole'");

        // RemoveUserRole for own user (line 1048)
        RemoveUserRole('admin', $rolePropId);

        // AddSeasonUserRole for own user (line 1107)
        AddSeasonUserRole('admin', 'spiritadmin:HRN2026', 'HRN2026');

        // RemoveSeasonUserRole for own user (line 1137)
        RemoveSeasonUserRole('admin', 'spiritadmin:HRN2026', 'HRN2026');

        // Session reflects superadmin still intact
        $this->assertArrayHasKey('superadmin', $_SESSION['userproperties']['userrole']);

        // Cleanup any residual rows
        DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='userrole' AND value IN ('testtestrole','spiritadmin:HRN2026')");
        DBQuery("DELETE FROM uo_userproperties WHERE userid='admin' AND name='editseason' AND value='HRN2026'");
    }

    // ---- hasEditGamePlayersRight / hasEditGameEventsRight ----

    public function testHasEditGamePlayersRightReturnsFalseWithoutRole(): void
    {
        LegacyApp::requireTopLevelLib('game.functions.php');
        // No session roles set → returns false
        $_SESSION = [];
        $this->assertFalse(hasEditGamePlayersRight(700));
    }

    public function testHasEditGamePlayersRightReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::requireTopLevelLib('game.functions.php');
        LegacyApp::loginAsAdmin();
        try {
            $result = hasEditGamePlayersRight(700);
            $this->assertTrue($result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testHasEditGameEventsRightReturnsFalseWithoutRole(): void
    {
        LegacyApp::requireTopLevelLib('game.functions.php');
        $_SESSION = [];
        $this->assertFalse(hasEditGameEventsRight(700));
    }

    public function testHasEditGameEventsRightReturnsTrueForSuperAdmin(): void
    {
        LegacyApp::requireTopLevelLib('game.functions.php');
        LegacyApp::loginAsAdmin();
        try {
            $result = hasEditGameEventsRight(700);
            $this->assertTrue($result);
        } finally {
            $_SESSION = [];
        }
    }

    // ---- EventScopedRolePrefixes ----

    public function testEventScopedRolePrefixesReturnsExpectedRoles(): void
    {
        $prefixes = EventScopedRolePrefixes();
        $this->assertIsArray($prefixes);
        $this->assertContains('seasonadmin', $prefixes);
        $this->assertContains('seriesadmin', $prefixes);
        $this->assertContains('teamadmin', $prefixes);
    }

    // ---- EventUserRoleCleanupPreview / DeleteEventUserRoles / DeleteUserRoleRows ----

    public function testEventUserRoleCleanupPreviewReturnsSafeArrayAsSuperAdmin(): void
    {
        LegacyApp::loginAsAdmin();
        try {
            // HRN2026 has admin user as superadmin (not season-scoped) → preview returns empty or low count
            $rows = EventUserRoleCleanupPreview('HRN2026');
            $this->assertIsArray($rows);
        } finally {
            $_SESSION = [];
        }
    }

    public function testDeleteEventUserRolesReturnZeroForSeasonWithNoScopedRoles(): void
    {
        LegacyApp::loginAsAdmin();
        try {
            // HRN2026 has no season-scoped admin roles in fixture → deletes 0
            $result = DeleteEventUserRoles('HRN2026');
            $this->assertSame(0, $result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testDeleteSelectedUsersEventRolesReturnsZeroForEmptyInput(): void
    {
        LegacyApp::loginAsAdmin();
        try {
            $result = DeleteSelectedUsersEventRoles([]);
            $this->assertSame(0, $result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testDeleteSelectedUsersEventRolesReturnsZeroForBlankUserId(): void
    {
        LegacyApp::loginAsAdmin();
        try {
            $result = DeleteSelectedUsersEventRoles(['']);
            $this->assertSame(0, $result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testDeleteUserRoleRowsReturnsZeroForEmptyRows(): void
    {
        LegacyApp::loginAsAdmin();
        try {
            $result = DeleteUserRoleRows([], 'test-source');
            $this->assertSame(0, $result);
        } finally {
            $_SESSION = [];
        }
    }

    // ---- Email-disabled early return (IsEmailDisabled returns true in test env) ----

    public function testAddRegisterRequestReturnsFalseWhenEmailDisabled(): void
    {
        // IsEmailDisabled() returns true in the test container
        $result = AddRegisterRequest('testuser_reg', 'pass123', 'Test User', 'test@example.com');
        $this->assertFalse($result);
    }

    public function testAddExtraEmailRequestReturnsFalseWhenEmailDisabled(): void
    {
        $result = AddExtraEmailRequest('admin', 'extra@example.com');
        $this->assertFalse($result);
    }

    public function testUserResetPasswordReturnsFalseWhenEmailDisabled(): void
    {
        $result = UserResetPassword('admin');
        $this->assertFalse($result);
    }

    // --- UserAuthenticate ---

    public function testUserAuthenticateReturnsFalseOnWrongPasswordWithNoFailcallback(): void
    {
        $result = UserAuthenticate('admin', 'definitely-wrong-password', null);
        $this->assertFalse($result);
    }

    public function testUserAuthenticateInvokesFailcallbackOnWrongPassword(): void
    {
        $called = false;
        UserAuthenticate('admin', 'wrong', static function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }
}
