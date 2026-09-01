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
        $this->assertGreaterThan(0, $result);
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
        $this->assertGreaterThan(0, $id);
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
        $this->assertGreaterThan(0, $result);
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
            "INSERT INTO uo_player (firstname, lastname, team, num, accredited) VALUES ('Zoe', 'Zephyr', 300, 99, 1)",
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
             VALUES ('$firstName', '$lastName', 'proftest@harness.invalid', 'HARN-ACCR-TEST')",
        );
        $playerId = (int) DBQueryInsert(
            "INSERT INTO uo_player (firstname, lastname, team, profile_id, accreditation_id, accredited)
             VALUES ('$firstName', '$lastName', 300, $profileId, 'HARN-ACCR-TEST', 1)",
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
            "INSERT INTO uo_player_profile (firstname, lastname) VALUES (NULL, NULL)",
        );
        $playerId = (int) DBQueryInsert(
            "INSERT INTO uo_player (firstname, lastname, team, profile_id, accredited)
             VALUES ('AnonFirst', 'AnonLast', 300, $profileId, 0)",
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
            $this->assertGreaterThan(0, $result);
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

    // ===== uo_game_history coverage =====
    //
    // Fixture game 700 (baseline.sql) is reused as the FK target for every
    // uo_game_history row inserted below; each test deletes exactly the
    // history_id(s) and player_id(s) it created, never a blanket delete by
    // game, to avoid colliding with GamehistoryFunctionsLibTest's own use of
    // game 700/701.

    private function insertThrowawayPlayer(string $firstname, string $lastname): int
    {
        return (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_player (firstname, lastname, team, num, accredited) VALUES ('%s', '%s', 300, 90, 1)",
            DBEscapeString($firstname),
            DBEscapeString($lastname),
        ));
    }

    private function insertGameHistorySnapshotRow(?array $snapshot, string $userId = 'admin', string $ip = '203.0.113.9'): int
    {
        $json = $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        if ($snapshot === null) {
            return (int) DBQueryInsert(sprintf(
                "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot, detail)
                 VALUES (700, '%s', '%s', 'harness', 'played', 'add', 0, NULL, '%s')",
                DBEscapeString($userId),
                DBEscapeString($ip),
                DBEscapeString(json_encode(['note' => 'no snapshot'])),
            ));
        }
        return (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot)
             VALUES (700, '%s', '%s', 'harness', 'snapshot', 'capture', 1, '%s')",
            DBEscapeString($userId),
            DBEscapeString($ip),
            DBEscapeString((string) $json),
        ));
    }

    /**
     * Minimal snapshot matching GameHistoryBuildSnapshot()'s shape, with only
     * the fields these tests need populated.
     */
    private function buildSnapshot(array $played, array $goals, string $official, string $comment, array $events): array
    {
        return [
            'v' => 1,
            'game' => [
                'homescore' => 1, 'visitorscore' => 0, 'isongoing' => 0,
                'hasstarted' => 1, 'forfeit' => 0, 'official' => $official, 'halftime' => null,
            ],
            'goals' => $goals,
            'played' => $played,
            'defenses' => [],
            'timeouts' => [],
            'spirit_timeouts' => [],
            'events' => $events,
            'comment' => $comment,
        ];
    }

    private function cleanupGameHistoryRows(array $historyIds): void
    {
        if (empty($historyIds)) {
            return;
        }
        $idList = implode(',', array_map('intval', $historyIds));
        DBQuery("DELETE FROM uo_game_history WHERE history_id IN ($idList)");
    }

    public function testPrivacyAnonymizePlayerRewritesSnapshotNameFieldsAndLeavesOtherFreeTextAlone(): void
    {
        $playerId = $this->insertThrowawayPlayer('Rewrite', 'Target');
        $snapshot = $this->buildSnapshot(
            played: [[
                'player' => $playerId, 'team' => 300, 'num' => 90, 'name' => 'Rewrite Target',
                'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1,
            ]],
            goals: [[
                'num' => 1, 'assist' => $playerId, 'scorer' => $playerId, 'time' => 10,
                'homescore' => 1, 'visitorscore' => 0, 'ishomegoal' => 1, 'iscallahan' => 0,
                'assist_num' => 90, 'assist_name' => 'Rewrite Target',
                'scorer_num' => 90, 'scorer_name' => 'Rewrite Target',
            ]],
            official: 'Rewrite Target',
            comment: 'Great game by Rewrite Target',
            events: [['num' => 0, 'time' => 10, 'type' => 'offence', 'ishome' => 1, 'info' => 'Rewrite Target signalled a foul']],
        );
        $historyId = $this->insertGameHistorySnapshotRow($snapshot);
        try {
            self::flushQueryCaches();
            $result = PrivacyAnonymizePlayer($playerId, 'admin');
            $this->assertTrue($result);
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
            $decoded = json_decode($row['snapshot'], true);
            $this->assertNotNull($decoded);

            $this->assertSame('- -', $decoded['played'][0]['name']);
            $this->assertSame('- -', $decoded['goals'][0]['scorer_name']);
            $this->assertSame('- -', $decoded['goals'][0]['assist_name']);

            // Free text elsewhere in the same snapshot is not player-id-keyed
            // and must be left untouched, even though it repeats the same
            // name text.
            $this->assertSame('Rewrite Target', $decoded['game']['official']);
            $this->assertSame('Great game by Rewrite Target', $decoded['comment']);
            $this->assertSame('Rewrite Target signalled a foul', $decoded['events'][0]['info']);
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyAnonymizePlayerDoesNotRewriteAnotherPlayerWhoseNameIsAPrefix(): void
    {
        $playerA = $this->insertThrowawayPlayer('Jan', 'Ek');
        $playerB = $this->insertThrowawayPlayer('Jan', 'Ekstrom');
        $snapshot = $this->buildSnapshot(
            played: [
                ['player' => $playerA, 'team' => 300, 'num' => 90, 'name' => 'Jan Ek',
                    'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1],
                ['player' => $playerB, 'team' => 300, 'num' => 91, 'name' => 'Jan Ekstrom',
                    'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1],
            ],
            goals: [],
            official: 'Ref',
            comment: '',
            events: [],
        );
        $historyId = $this->insertGameHistorySnapshotRow($snapshot);
        try {
            self::flushQueryCaches();
            $this->assertTrue(PrivacyAnonymizePlayer($playerA, 'admin'));
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
            $decoded = json_decode($row['snapshot'], true);
            $this->assertNotNull($decoded);
            $this->assertSame('- -', $decoded['played'][0]['name']);
            $this->assertSame('Jan Ekstrom', $decoded['played'][1]['name']);
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id IN ($playerA, $playerB)");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyAnonymizePlayerDoesNotRewriteADifferentPlayerWithTheSameName(): void
    {
        // Finding 2's actual case: two distinct players sharing a
        // byte-identical display name in the same snapshot. Under name-text
        // matching this was impossible to get right (either both are
        // rewritten, or neither); id-keyed matching disambiguates them
        // because the two players never share a player_id.
        $playerA = $this->insertThrowawayPlayer('Sam', 'Rivera');
        $playerB = $this->insertThrowawayPlayer('Sam', 'Rivera');
        $snapshot = $this->buildSnapshot(
            played: [
                ['player' => $playerA, 'team' => 300, 'num' => 90, 'name' => 'Sam Rivera',
                    'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1],
                ['player' => $playerB, 'team' => 300, 'num' => 91, 'name' => 'Sam Rivera',
                    'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1],
            ],
            goals: [[
                'num' => 1, 'assist' => $playerB, 'scorer' => $playerA, 'time' => 10,
                'homescore' => 1, 'visitorscore' => 0, 'ishomegoal' => 1, 'iscallahan' => 0,
                'assist_num' => 91, 'assist_name' => 'Sam Rivera',
                'scorer_num' => 90, 'scorer_name' => 'Sam Rivera',
            ]],
            official: 'Ref',
            comment: '',
            events: [],
        );
        $historyId = $this->insertGameHistorySnapshotRow($snapshot);
        try {
            self::flushQueryCaches();
            $this->assertTrue(PrivacyAnonymizePlayer($playerA, 'admin'));
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
            $decoded = json_decode($row['snapshot'], true);
            $this->assertNotNull($decoded);

            $this->assertSame('- -', $decoded['played'][0]['name'], 'the anonymized player (playerA) must be rewritten');
            $this->assertSame('Sam Rivera', $decoded['played'][1]['name'], 'the other player (playerB) sharing the same name must be untouched');
            $this->assertSame('- -', $decoded['goals'][0]['scorer_name'], 'playerA is the scorer and must be rewritten');
            $this->assertSame('Sam Rivera', $decoded['goals'][0]['assist_name'], 'playerB is the assist and must be untouched');
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id IN ($playerA, $playerB)");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyAnonymizePlayerRewritesNamesWithJsonSpecialAndUnicodeCharacters(): void
    {
        $cases = ['O"Hare', 'Back\\Slash', 'For/Ward', "O'Brien", 'Hakkinen-a with umlaut ä'];
        foreach ($cases as $lastname) {
            $playerId = $this->insertThrowawayPlayer('Case', $lastname);
            $snapshot = $this->buildSnapshot(
                played: [[
                    'player' => $playerId, 'team' => 300, 'num' => 90, 'name' => 'Case ' . $lastname,
                    'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1,
                ]],
                goals: [],
                official: 'Ref',
                comment: '',
                events: [],
            );
            $historyId = $this->insertGameHistorySnapshotRow($snapshot);
            try {
                self::flushQueryCaches();
                $this->assertTrue(PrivacyAnonymizePlayer($playerId, 'admin'), "failed for lastname: $lastname");
                self::flushQueryCaches();

                $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
                $decoded = json_decode($row['snapshot'], true);
                $this->assertNotNull($decoded, "invalid JSON after rewrite for lastname: $lastname");
                $this->assertSame('- -', $decoded['played'][0]['name'], "not rewritten for lastname: $lastname");
            } finally {
                $this->cleanupGameHistoryRows([$historyId]);
                DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
                DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
                self::flushQueryCaches();
            }
        }
    }

    public function testPrivacyAnonymizePlayerRewritesPlayerWithEmptyLastname(): void
    {
        // uo_player.firstname/lastname default to '', not NULL, so a
        // single-name player's stored snapshot name carries a CONCAT_WS
        // trailing space ("Solo "). Matching is by player id, not by
        // reconstructing that text, so this must still be rewritten.
        $playerId = $this->insertThrowawayPlayer('Solo', '');
        $snapshot = $this->buildSnapshot(
            played: [[
                'player' => $playerId, 'team' => 300, 'num' => 90, 'name' => 'Solo ',
                'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1,
            ]],
            goals: [],
            official: 'Ref',
            comment: '',
            events: [],
        );
        $historyId = $this->insertGameHistorySnapshotRow($snapshot);
        try {
            self::flushQueryCaches();
            $this->assertTrue(PrivacyAnonymizePlayer($playerId, 'admin'));
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
            $decoded = json_decode($row['snapshot'], true);
            $this->assertNotNull($decoded);
            $this->assertSame('- -', $decoded['played'][0]['name']);
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyAnonymizePlayerRewritesPlayerWithEmptyFirstname(): void
    {
        // Mirror of the empty-lastname case above, but with the empty field
        // first: CONCAT_WS(' ', '', 'Anders') stores a LEADING space
        // ("Anders" preceded by a space), not a trailing one. The old
        // name-capture logic trimmed this to "Anders" either way, so both
        // the leading- and trailing-space forms need their own case.
        $playerId = $this->insertThrowawayPlayer('', 'Anders');
        $snapshot = $this->buildSnapshot(
            played: [[
                'player' => $playerId, 'team' => 300, 'num' => 90, 'name' => ' Anders',
                'captain' => 0, 'spirit_captain' => 0, 'accredited' => 1, 'acknowledged' => 1,
            ]],
            goals: [],
            official: 'Ref',
            comment: '',
            events: [],
        );
        $historyId = $this->insertGameHistorySnapshotRow($snapshot);
        try {
            self::flushQueryCaches();
            $this->assertTrue(PrivacyAnonymizePlayer($playerId, 'admin'));
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$historyId");
            $decoded = json_decode($row['snapshot'], true);
            $this->assertNotNull($decoded);
            $this->assertSame('- -', $decoded['played'][0]['name']);
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyAnonymizePlayerLeavesNonSnapshotRowsAlone(): void
    {
        $playerId = $this->insertThrowawayPlayer('NoSnap', 'Shot');
        $historyId = $this->insertGameHistorySnapshotRow(null);
        try {
            self::flushQueryCaches();
            $this->assertTrue(PrivacyAnonymizePlayer($playerId, 'admin'));
            self::flushQueryCaches();

            $row = DBQueryToRow("SELECT has_snapshot, snapshot FROM uo_game_history WHERE history_id=$historyId");
            $this->assertSame(0, (int) $row['has_snapshot']);
            $this->assertNull($row['snapshot']);
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyDeleteUserDataAnonymizesGameHistoryRowsInPlaceInsteadOfDeletingThem(): void
    {
        DBQuery("INSERT INTO uo_users (userid, name, email) VALUES ('privacy_gh_del_test', 'GH Delete Me', 'ghdel@test.invalid')");
        $snapshotJson = json_encode(['v' => 1, 'game' => ['homescore' => 3, 'visitorscore' => 2], 'note' => 'unchanged']);
        $snapshotId = (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot)
             VALUES (700, 'privacy_gh_del_test', '198.51.100.5', 'harness', 'snapshot', 'capture', 1, '%s')",
            DBEscapeString($snapshotJson),
        ));
        $detailJson = json_encode(['player' => 900, 'num' => 5]);
        $detailId = (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, detail)
             VALUES (700, 'privacy_gh_del_test', '198.51.100.5', 'harness', 'played', 'add', 0, '%s')",
            DBEscapeString($detailJson),
        ));
        try {
            self::flushQueryCaches();
            $before = DBQueryToArray(
                "SELECT history_id, game, snapshot, detail FROM uo_game_history WHERE history_id IN ($snapshotId, $detailId) ORDER BY history_id",
            );
            $this->assertCount(2, $before);

            $result = PrivacyDeleteUserData('privacy_gh_del_test', 'admin');
            $this->assertTrue($result);
            self::flushQueryCaches();

            $after = DBQueryToArray(
                "SELECT history_id, game, user_id, ip, snapshot, detail FROM uo_game_history WHERE history_id IN ($snapshotId, $detailId) ORDER BY history_id",
            );
            $this->assertCount(2, $after, 'rows must be anonymized in place, not deleted');
            foreach ($after as $i => $row) {
                $this->assertSame('-', $row['user_id']);
                $this->assertNull($row['ip']);
                $this->assertSame($before[$i]['game'], $row['game']);
                $this->assertSame($before[$i]['snapshot'], $row['snapshot']);
                $this->assertSame($before[$i]['detail'], $row['detail']);
            }
        } finally {
            $this->cleanupGameHistoryRows([$snapshotId, $detailId]);
            DBQuery("DELETE FROM uo_users WHERE userid='privacy_gh_del_test'");
            DBQuery("DELETE FROM uo_event_log WHERE source='privacy'");
            self::flushQueryCaches();
        }
    }

    public function testPrivacyCollectUserReportDataExcludesSnapshotColumnButIncludesTheUsersOwnRow(): void
    {
        // 'target' legitimately equals the literal string "snapshot" for
        // real capture rows, so the leak this checks for is the embedded
        // snapshot *content*, not the bare word: a marker planted only in
        // `snapshot` must not surface, while a marker planted in `detail`
        // (a column the export does include) must.
        $snapshotJson = json_encode(['marker' => 'PRIVACY_SNAPSHOT_MARKER_SHOULD_NOT_LEAK']);
        $ip = '203.0.113.77';
        $historyId = (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot, detail)
             VALUES (700, 'admin', '%s', 'harness', 'played', 'update', 1, '%s', '%s')",
            DBEscapeString($ip),
            DBEscapeString($snapshotJson),
            DBEscapeString(json_encode(['marker' => 'PRIVACY_DETAIL_MARKER_SHOULD_APPEAR'])),
        ));
        try {
            self::flushQueryCaches();
            $data = PrivacyCollectUserReportData('admin');
            $this->assertArrayHasKey('game_history_rows', $data);
            $found = null;
            foreach ($data['game_history_rows'] as $row) {
                if ((int) $row['history_id'] === $historyId) {
                    $found = $row;
                }
            }
            $this->assertNotNull($found, 'the row for this user must be present in the export');
            $this->assertArrayNotHasKey('snapshot', $found);
            $this->assertSame($ip, $found['ip'], 'the stored ip must be part of the exported row');
            $this->assertSame('admin', $found['user_id']);

            $report = PrivacyRenderUserReportText('admin', 'admin');
            $this->assertNotNull($report);
            $this->assertStringContainsString('PRIVACY_DETAIL_MARKER_SHOULD_APPEAR', $report);
            $this->assertStringNotContainsString('PRIVACY_SNAPSHOT_MARKER_SHOULD_NOT_LEAK', $report);
            $this->assertStringContainsString($ip, $report, 'the exported report text must include the stored ip address');
        } finally {
            $this->cleanupGameHistoryRows([$historyId]);
            self::flushQueryCaches();
        }
    }
}
