<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PrivacyFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // privacy.functions transitively requires common/player/user/image/url/logging
        LegacyApp::loadLibFileUsingProfile('privacy.functions.php', 'database_only');
        // Most privacy functions call PrivacyRequireSuperAdmin() (Forbidden-exits otherwise)
        LegacyApp::loginAsAdmin();
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        LegacyApp::closeDatabaseConnection();
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }

    // ===== Pure helpers (no DB / no superadmin needed) =====

    public function testPrivacySlugPassesThroughAlreadyCleanValue(): void
    {
        $this->assertSame('hello-world', PrivacySlug('hello-world'));
    }

    public function testPrivacySlugReplacesSpacesAndSpecialChars(): void
    {
        $this->assertSame('Hello-World', PrivacySlug('Hello World!'));
    }

    public function testPrivacySlugPreservesDotsUnderscoresAndDashes(): void
    {
        $this->assertSame('John_Smith.99', PrivacySlug('John_Smith.99'));
    }

    public function testPrivacySlugReturnsSubjectForEmptyString(): void
    {
        $this->assertSame('subject', PrivacySlug(''));
        $this->assertSame('subject', PrivacySlug('---'));
    }

    public function testPrivacyScalarToTextHandlesSpecialValues(): void
    {
        $this->assertSame('(null)', PrivacyScalarToText(null));
        $this->assertSame('(empty)', PrivacyScalarToText(''));
        $this->assertSame('1', PrivacyScalarToText(true));
        $this->assertSame('0', PrivacyScalarToText(false));
        $this->assertSame('["a","b"]', PrivacyScalarToText(['a', 'b']));
        $this->assertSame('line1\nline2', PrivacyScalarToText("line1\nline2"));
        $this->assertSame('plain', PrivacyScalarToText('plain'));
    }

    public function testPrivacyIntListFiltersNonPositiveIntegers(): void
    {
        $this->assertSame('1,2,3', PrivacyIntList([1, '2', 3, 0, -5, 'x']));
        $this->assertSame('', PrivacyIntList([]));
    }

    public function testPrivacyQuotedListQuotesAndEscapesNonEmptyValues(): void
    {
        $this->assertSame("'a','b'", PrivacyQuotedList(['a', '', 'b', '  ']));
        $this->assertSame('', PrivacyQuotedList([]));
    }

    public function testPrivacyUserEventLogWhereBuildsOrClause(): void
    {
        $where = PrivacyUserEventLogWhere('admin');
        $this->assertStringContainsString("user_id='admin'", $where);
        $this->assertStringContainsString("id1='admin'", $where);
        $this->assertStringContainsString("id2='admin'", $where);
    }

    public function testPrivacySanitizePlayerPrivacyRowsHidesUserIds(): void
    {
        $rows = [
            ['user_id' => 'secret', 'data' => 'x'],
            ['userid' => 'secret2', 'data' => 'y'],
            ['data' => 'z'],
        ];
        $sanitized = PrivacySanitizePlayerPrivacyRows($rows);
        $this->assertSame('(hidden)', $sanitized[0]['user_id']);
        $this->assertSame('(hidden)', $sanitized[1]['userid']);
        $this->assertSame('z', $sanitized[2]['data']);
    }

    public function testPrivacySanitizePlayerEventLogRowsDelegates(): void
    {
        $rows = [['user_id' => 'secret']];
        $this->assertSame('(hidden)', PrivacySanitizePlayerEventLogRows($rows)[0]['user_id']);
    }

    public function testPrivacyPlayerMatchSortOrdersByNameThenIds(): void
    {
        $a = ['player_name' => 'Alpha', 'season_name' => '', 'series_name' => '', 'team_name' => '', 'player_id' => 1];
        $b = ['player_name' => 'Beta', 'season_name' => '', 'series_name' => '', 'team_name' => '', 'player_id' => 2];
        $this->assertLessThan(0, PrivacyPlayerMatchSort($a, $b));
        $this->assertGreaterThan(0, PrivacyPlayerMatchSort($b, $a));
    }

    public function testPrivacyPlayerMatchSortTieBreaksOnPlayerId(): void
    {
        $base = ['player_name' => 'Same', 'season_name' => 'S', 'series_name' => 'D', 'team_name' => 'T'];
        $a = $base + ['player_id' => 5];
        $b = $base + ['player_id' => 9];
        $this->assertLessThan(0, PrivacyPlayerMatchSort($a, $b));
    }

    public function testPrivacyPlayerIdentityLabelBuildsReadableLabel(): void
    {
        $subject = ['selected' => [
            'firstname' => 'Ari', 'lastname' => 'Ace',
            'teamname' => 'Helsinki Heat', 'seriesname' => 'Open', 'season_name' => 'HRN',
        ]];
        $label = PrivacyPlayerIdentityLabel($subject);
        $this->assertStringContainsString('Ari Ace', $label);
        $this->assertStringContainsString('team=Helsinki Heat', $label);
    }

    public function testPrivacyPlayerIdentityLabelHandlesUnnamedPlayer(): void
    {
        $subject = ['selected' => ['firstname' => '', 'lastname' => '']];
        $this->assertStringContainsString('(unnamed player)', PrivacyPlayerIdentityLabel($subject));
    }

    public function testPrivacyAppendRowsSectionFormatsRows(): void
    {
        $lines = [];
        PrivacyAppendRowsSection($lines, 'My Section', [['a' => '1', 'b' => '2']]);
        $text = implode("\n", $lines);
        $this->assertStringContainsString('=== My Section ===', $text);
        $this->assertStringContainsString('Row count: 1', $text);
        $this->assertStringContainsString('a: 1', $text);
    }

    public function testPrivacyAppendRowsSectionHandlesEmpty(): void
    {
        $lines = [];
        PrivacyAppendRowsSection($lines, 'Empty', []);
        $text = implode("\n", $lines);
        $this->assertStringContainsString('Row count: 0', $text);
        $this->assertStringContainsString('(none)', $text);
    }

    public function testPrivacyUserReportFilenameUsesSlug(): void
    {
        $this->assertSame('user-privacy-report-admin.txt', PrivacyUserReportFilename('admin'));
    }

    // ===== DB-backed report reads (superadmin set in setUp) =====

    public function testPrivacyPlayerMatchesFindsFixturePlayer(): void
    {
        $matches = PrivacyPlayerMatches('Ace');
        $this->assertNotEmpty($matches);
        $names = array_column($matches, 'player_name');
        $this->assertContains('Ari Ace', $names);
    }

    public function testPrivacyPlayerMatchesReturnsEmptyForBlankSearch(): void
    {
        $this->assertSame([], PrivacyPlayerMatches('   '));
    }

    public function testPrivacyPlayerMatchesReturnsEmptyForNoMatch(): void
    {
        $this->assertSame([], PrivacyPlayerMatches('NoSuchPlayerXYZ'));
    }

    public function testPrivacyGetPlayerSubjectReturnsSubjectForFixturePlayer(): void
    {
        $subject = PrivacyGetPlayerSubject(800);
        $this->assertIsArray($subject);
        $this->assertArrayHasKey('selected', $subject);
        $this->assertSame(800, (int) $subject['selected']['player_id']);
    }

    public function testPrivacyGetPlayerSubjectReturnsNullForInvalidId(): void
    {
        $this->assertNull(PrivacyGetPlayerSubject(0));
        $this->assertNull(PrivacyGetPlayerSubject(999999));
    }

    public function testPrivacyCollectPlayerReportDataReturnsSections(): void
    {
        $data = PrivacyCollectPlayerReportData(800);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('subject', $data);
        $this->assertArrayHasKey('player_rows', $data);
    }

    public function testPrivacyRenderPlayerReportTextProducesReport(): void
    {
        $report = PrivacyRenderPlayerReportText(800, 'admin');
        $this->assertIsString($report);
        $this->assertStringContainsString('Ultiorganizer Privacy Report', $report);
        $this->assertStringContainsString('Subject type: player', $report);
    }

    public function testPrivacyRenderPlayerReportTextReturnsNullForMissingPlayer(): void
    {
        $this->assertNull(PrivacyRenderPlayerReportText(999999, 'admin'));
    }

    public function testPrivacyPlayerReportFilenameReflectsSubject(): void
    {
        $filename = PrivacyPlayerReportFilename(800);
        $this->assertStringStartsWith('player-privacy-report-', $filename);
        $this->assertStringEndsWith('.txt', $filename);
    }

    public function testPrivacyPlayerReportFilenameFallsBackForMissingPlayer(): void
    {
        $this->assertSame('player-privacy-report.txt', PrivacyPlayerReportFilename(999999));
    }

    public function testPrivacyLogPlayerReportExportLogsAndReturnsId(): void
    {
        $result = PrivacyLogPlayerReportExport(800, 'admin');
        $this->assertNotFalse($result);
        self::flushQueryCaches();
        $count = DBQueryToValue("SELECT COUNT(*) FROM uo_event_log WHERE source='privacy' AND category='security'");
        $this->assertGreaterThan(0, (int) $count);
        DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
    }

    public function testPrivacyLogPlayerReportExportReturnsFalseForMissingPlayer(): void
    {
        $this->assertFalse(PrivacyLogPlayerReportExport(999999, 'admin'));
    }

    public function testPrivacyLogOperationWritesSecurityEvent(): void
    {
        $id = PrivacyLogOperation('admin', 'test privacy operation', 'player:800');
        $this->assertNotFalse($id);
        DBQuery("DELETE FROM uo_event_log WHERE event_id=" . (int) $id);
    }

    // ===== Registered-user report reads =====

    public function testPrivacyUserMatchesFindsAdminUser(): void
    {
        $matches = PrivacyUserMatches('admin');
        $this->assertIsArray($matches);
        $this->assertNotEmpty($matches);
    }

    public function testPrivacyUserMatchesReturnsEmptyForBlankSearch(): void
    {
        $this->assertSame([], PrivacyUserMatches('   '));
    }

    public function testPrivacyGetUserSubjectReturnsAdminSubject(): void
    {
        $subject = PrivacyGetUserSubject('admin');
        $this->assertIsArray($subject);
        $this->assertArrayHasKey('user', $subject);
    }

    public function testPrivacyGetUserSubjectReturnsNullForMissingUser(): void
    {
        $this->assertNull(PrivacyGetUserSubject('nosuchuser_xyz'));
    }

    public function testPrivacyCollectUserReportDataReturnsSections(): void
    {
        $data = PrivacyCollectUserReportData('admin');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('user_row', $data);
    }

    public function testPrivacyRenderUserReportTextProducesReport(): void
    {
        $report = PrivacyRenderUserReportText('admin', 'admin');
        $this->assertIsString($report);
        $this->assertStringContainsString('Subject type: registered user', $report);
    }

    public function testPrivacyRenderUserReportTextReturnsNullForMissingUser(): void
    {
        $this->assertNull(PrivacyRenderUserReportText('nosuchuser_xyz', 'admin'));
    }

    public function testPrivacyLogUserReportExportLogsAndReturnsId(): void
    {
        $result = PrivacyLogUserReportExport('admin', 'admin');
        $this->assertNotFalse($result);
        DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
    }

    public function testPrivacyLogUserReportExportReturnsFalseForMissingUser(): void
    {
        $this->assertFalse(PrivacyLogUserReportExport('nosuchuser_xyz', 'admin'));
    }

    // ===== PrivacyRemovePlayerProfileImageByProfileId / PrivacyRemoveEmptyDirectory =====

    public function testPrivacyRemovePlayerProfileImageEarlyReturnOnZeroProfileId(): void
    {
        PrivacyRemovePlayerProfileImageByProfileId(0, 'photo.jpg');
        $this->assertTrue(true);
    }

    public function testPrivacyRemovePlayerProfileImageEarlyReturnOnEmptyFilename(): void
    {
        PrivacyRemovePlayerProfileImageByProfileId(1, '');
        $this->assertTrue(true);
    }

    public function testPrivacyRemovePlayerProfileImageDeletesBothFilesWhenPresent(): void
    {
        $uploadDir = constant('UPLOAD_DIR');
        $profileId = 88888;
        $filename = 'test_privacy.jpg';
        $imageDir = $uploadDir . "players/$profileId";
        $thumbDir = $imageDir . '/thumbs';

        mkdir($thumbDir, 0777, true);
        file_put_contents($thumbDir . '/' . $filename, 'fake-thumb');
        file_put_contents($imageDir . '/' . $filename, 'fake-image');

        try {
            PrivacyRemovePlayerProfileImageByProfileId($profileId, $filename);

            $this->assertFileDoesNotExist($thumbDir . '/' . $filename);
            $this->assertFileDoesNotExist($imageDir . '/' . $filename);
            // Empty directories are also removed by the function
            $this->assertDirectoryDoesNotExist($thumbDir);
            $this->assertDirectoryDoesNotExist($imageDir);
        } finally {
            // Clean up in case the function didn't fully remove things
            foreach ([$thumbDir . '/' . $filename, $imageDir . '/' . $filename] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            foreach ([$thumbDir, $imageDir] as $d) {
                if (is_dir($d)) {
                    rmdir($d);
                }
            }
        }
    }

    public function testPrivacyRemovePlayerProfileImageHandlesMissingFilesGracefully(): void
    {
        // profileId/filename valid but no files on disk — should not throw
        PrivacyRemovePlayerProfileImageByProfileId(88889, 'nonexistent.jpg');
        $this->assertTrue(true);
    }

    // ===== PrivacyDeleteUserData =====

    public function testPrivacyDeleteUserDataReturnsFalseForMissingUser(): void
    {
        $this->assertFalse(PrivacyDeleteUserData('no_such_user_xyz', 'admin'));
    }

    public function testPrivacyDeleteUserDataDeletesThrowawayUser(): void
    {
        DBQuery("INSERT INTO uo_users (userid, name, email) VALUES ('privacy_del_test', 'Delete Me', 'del@test.invalid')");
        self::flushQueryCaches();
        try {
            $result = PrivacyDeleteUserData('privacy_del_test', 'admin');
            $this->assertTrue($result);
            self::flushQueryCaches();
            $row = DBQueryToRow("SELECT userid FROM uo_users WHERE userid='privacy_del_test'");
            $this->assertNull($row);
        } finally {
            DBQuery("DELETE FROM uo_users WHERE userid='privacy_del_test'");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
        }
    }

    // ===== Destructive: anonymize on a throwaway player =====

    public function testPrivacyAnonymizePlayerOnThrowawayPlayer(): void
    {
        // Create a standalone player (no profile, no games) on fixture team 300
        $playerId = (int) DBQueryInsert(
            "INSERT INTO uo_player (firstname, lastname, team, num, accredited) VALUES ('Zoe', 'Zephyr', 300, 99, 1)"
        );
        try {
            self::flushQueryCaches();
            $result = PrivacyAnonymizePlayer($playerId, 'admin');
            $this->assertNotFalse($result);
            self::flushQueryCaches();
            // The player's identifying names should be cleared/anonymized
            $row = DBQueryToRow("SELECT firstname, lastname FROM uo_player WHERE player_id=$playerId");
            if ($row) {
                $this->assertNotSame('Zoe', $row['firstname']);
            } else {
                $this->assertTrue(true); // row removed entirely is also acceptable
            }
        } finally {
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
        }
    }

    // ===== Profiled player helpers and tests =====

    private function insertProfiledPlayer(string $firstName = 'Profiled', string $lastName = 'TestPerson'): array
    {
        $profileId = (int) DBQueryInsert(
            "INSERT INTO uo_player_profile (firstname, lastname, email, accreditation_id)
             VALUES ('$firstName', '$lastName', 'proftest@harness.invalid', 'HARN-ACCR-TEST')"
        );
        $playerId = (int) DBQueryInsert(
            "INSERT INTO uo_player (firstname, lastname, team, profile_id, accreditation_id, accredited)
             VALUES ('$firstName', '$lastName', 300, $profileId, 'HARN-ACCR-TEST', 1)"
        );
        self::flushQueryCaches();
        return [$profileId, $playerId];
    }

    private function cleanupProfiledPlayer(int $profileId, int $playerId): void
    {
        DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
        DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
        DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
        self::flushQueryCaches();
    }

    public function testPrivacyPlayerMatchesFindsProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $matches = PrivacyPlayerMatches('Profiled');
            $found = array_filter($matches, fn($m) => (int) $m['profile_id'] === $profileId);
            $this->assertNotEmpty($found);
        } finally {
            $this->cleanupProfiledPlayer($profileId, $playerId);
        }
    }

    public function testPrivacyPlayerMatchesFallsBackToPlayerNameForUnnamedProfile(): void
    {
        // Profile with null names → displayName falls back to player name (line 65)
        $profileId = (int) DBQueryInsert(
            "INSERT INTO uo_player_profile (firstname, lastname) VALUES (NULL, NULL)"
        );
        $playerId = (int) DBQueryInsert(
            "INSERT INTO uo_player (firstname, lastname, team, profile_id, accredited)
             VALUES ('AnonFirst', 'AnonLast', 300, $profileId, 0)"
        );
        self::flushQueryCaches();
        try {
            $matches = PrivacyPlayerMatches('AnonFirst');
            $found = array_filter($matches, fn($m) => (int) $m['profile_id'] === $profileId);
            $this->assertNotEmpty($found);
        } finally {
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyGetPlayerSubjectReturnsProfileForProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $subject = PrivacyGetPlayerSubject($playerId);
            $this->assertNotNull($subject);
            $this->assertSame($profileId, (int) $subject['profile_id']);
            $this->assertNotNull($subject['profile']);
        } finally {
            $this->cleanupProfiledPlayer($profileId, $playerId);
        }
    }

    public function testPrivacyGetUserSubjectReturnsNullForEmptyUserId(): void
    {
        $this->assertNull(PrivacyGetUserSubject(''));
    }

    public function testPrivacyCollectPlayerReportDataWithProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $data = PrivacyCollectPlayerReportData($playerId);
            $this->assertArrayHasKey('player_rows', $data);
            $this->assertArrayHasKey('profile_row', $data);
        } finally {
            $this->cleanupProfiledPlayer($profileId, $playerId);
        }
    }

    public function testPrivacyRenderPlayerReportTextWithProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $text = PrivacyRenderPlayerReportText($playerId, 'admin');
            $this->assertNotNull($text);
            $this->assertStringContainsString('Profile image metadata', $text);
        } finally {
            $this->cleanupProfiledPlayer($profileId, $playerId);
        }
    }

    public function testPrivacyLogPlayerReportExportWithProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $result = PrivacyLogPlayerReportExport($playerId, 'admin');
            $this->assertNotFalse($result);
        } finally {
            $this->cleanupProfiledPlayer($profileId, $playerId);
        }
    }

    public function testPrivacyAnonymizePlayerReturnsFalseForMissingPlayer(): void
    {
        $this->assertFalse(PrivacyAnonymizePlayer(0, 'admin'));
    }

    public function testPrivacyAnonymizePlayerHandlesProfiledPlayer(): void
    {
        [$profileId, $playerId] = $this->insertProfiledPlayer();
        try {
            $result = PrivacyAnonymizePlayer($playerId, 'admin');
            $this->assertTrue($result);
            self::flushQueryCaches();
            $row = DBQueryToRow("SELECT firstname FROM uo_player WHERE player_id=$playerId");
            $this->assertSame('-', $row['firstname'] ?? '-');
        } finally {
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyPlayerMatchSortOrdersBySeasonName(): void
    {
        $a = ['player_name' => 'Same', 'season_name' => 'Autumn', 'series_name' => '', 'team_name' => '', 'player_id' => 1];
        $b = ['player_name' => 'Same', 'season_name' => 'Spring', 'series_name' => '', 'team_name' => '', 'player_id' => 2];
        $result = PrivacyPlayerMatchSort($a, $b);
        $this->assertLessThan(0, $result); // 'Autumn' < 'Spring'
    }

    public function testPrivacyPlayerMatchSortOrdersBySeriesName(): void
    {
        $a = ['player_name' => 'Same', 'season_name' => 'Same', 'series_name' => 'Alpha', 'team_name' => '', 'player_id' => 1];
        $b = ['player_name' => 'Same', 'season_name' => 'Same', 'series_name' => 'Zeta', 'team_name' => '', 'player_id' => 2];
        $result = PrivacyPlayerMatchSort($a, $b);
        $this->assertLessThan(0, $result); // 'Alpha' < 'Zeta'
    }

    public function testPrivacyPlayerMatchSortOrdersByTeamName(): void
    {
        $a = ['player_name' => 'Same', 'season_name' => 'Same', 'series_name' => 'Same', 'team_name' => 'A Team', 'player_id' => 1];
        $b = ['player_name' => 'Same', 'season_name' => 'Same', 'series_name' => 'Same', 'team_name' => 'Z Team', 'player_id' => 2];
        $result = PrivacyPlayerMatchSort($a, $b);
        $this->assertLessThan(0, $result); // 'A Team' < 'Z Team'
    }

    public function testPrivacyRemoveEmptyDirectoryDoesNotRemoveNonEmptyDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/privacy_test_nonempty_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/keepme.txt', 'data');
        try {
            PrivacyRemoveEmptyDirectory($dir);
            $this->assertDirectoryExists($dir);
        } finally {
            if (is_file($dir . '/keepme.txt')) {
                unlink($dir . '/keepme.txt');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
