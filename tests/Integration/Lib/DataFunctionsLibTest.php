<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Tests for data.functions.php (EventSnapshotService JSON import/export).
 *
 * Uses team_stack profile because data.functions.php requires
 * game.functions.php → pool/series/season chain.
 */
final class DataFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['data.functions.php'],
            'team_stack'
        );
        LegacyApp::requireTopLevelLib('user.functions.php');
        LegacyApp::requireTopLevelLib('configuration.functions.php');

        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['locale'] = 'en_US';
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
        $_SESSION = [];
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function exportHrn2026ToTempFile(): string
    {
        $json = EventSnapshotExportJson('HRN2026');
        $tmp = tempnam(sys_get_temp_dir(), 'uo_snap_') . '.json';
        file_put_contents($tmp, $json);
        return $tmp;
    }

    private function deleteImportedSeason(string $seasonId): void
    {
        $sid = DBEscapeString($seasonId);
        // Capture scheduling names referenced by this season's games before the
        // games are deleted; imported uo_scheduling_name rows are global (not
        // season-scoped) and would otherwise leak into other suites.
        $schedulingIds = [];
        $gameRows = DBQueryToArray("SELECT g.scheduling_name_home, g.scheduling_name_visitor, g.name FROM uo_game g LEFT JOIN uo_team ht ON g.hometeam=ht.team_id LEFT JOIN uo_series hs ON ht.series=hs.series_id WHERE hs.season='$sid'");
        foreach ($gameRows as $row) {
            foreach (['scheduling_name_home', 'scheduling_name_visitor', 'name'] as $col) {
                if (!empty($row[$col])) {
                    $schedulingIds[(int) $row[$col]] = true;
                }
            }
        }
        DBQuery("DELETE FROM uo_played WHERE game IN (SELECT game_id FROM uo_game g LEFT JOIN uo_team ht ON g.hometeam=ht.team_id LEFT JOIN uo_series hs ON ht.series=hs.series_id WHERE hs.season='$sid')");
        DBQuery("DELETE FROM uo_goal WHERE game IN (SELECT game_id FROM uo_game g LEFT JOIN uo_team ht ON g.hometeam=ht.team_id LEFT JOIN uo_series hs ON ht.series=hs.series_id WHERE hs.season='$sid')");
        DBQuery("DELETE FROM uo_game_pool WHERE game IN (SELECT game_id FROM uo_game g LEFT JOIN uo_team ht ON g.hometeam=ht.team_id LEFT JOIN uo_series hs ON ht.series=hs.series_id WHERE hs.season='$sid')");
        DBQuery("DELETE uo_game FROM uo_game LEFT JOIN uo_team ht ON uo_game.hometeam=ht.team_id LEFT JOIN uo_series hs ON ht.series=hs.series_id WHERE hs.season='$sid'");
        DBQuery("DELETE FROM uo_team_pool WHERE team IN (SELECT team_id FROM uo_team t LEFT JOIN uo_series s ON t.series=s.series_id WHERE s.season='$sid')");
        DBQuery("DELETE FROM uo_player WHERE team IN (SELECT team_id FROM uo_team t LEFT JOIN uo_series s ON t.series=s.series_id WHERE s.season='$sid')");
        DBQuery("DELETE FROM uo_team WHERE series IN (SELECT series_id FROM uo_series WHERE season='$sid')");
        DBQuery("DELETE FROM uo_pool WHERE series IN (SELECT series_id FROM uo_series WHERE season='$sid')");
        DBQuery("DELETE FROM uo_series WHERE season='$sid'");
        // Imported locations are global; deleting them cascades to the season's
        // reservations and location_info (fk_reservation_location /
        // fk_location_info_location are ON DELETE CASCADE).
        DBQuery("DELETE FROM uo_location WHERE id IN (SELECT location FROM uo_reservation WHERE season='$sid' AND location IS NOT NULL)");
        DBQuery("DELETE FROM uo_reservation WHERE season='$sid'");
        if (!empty($schedulingIds)) {
            $idList = implode(',', array_map('intval', array_keys($schedulingIds)));
            DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id IN ($idList)");
        }
        DBQuery("DELETE FROM uo_season WHERE season_id='$sid'");
    }

    // ── Export tests ───────────────────────────────────────────────────────

    public function testExportJsonReturnsValidJsonWithExpectedFormat(): void
    {
        $json = EventSnapshotExportJson('HRN2026');

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ultiorganizer.event-snapshot', $decoded['format']);
        $this->assertSame(2, $decoded['version']);
        $this->assertArrayHasKey('tables', $decoded);
        $this->assertArrayHasKey('uo_season', $decoded['tables']);
        $this->assertCount(1, $decoded['tables']['uo_season']);
        $this->assertSame('HRN2026', $decoded['tables']['uo_season'][0]['season_id']);
    }

    public function testExportJsonIncludesExpectedTables(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $decoded = json_decode($json, true);
        $tables = array_keys($decoded['tables']);

        $this->assertContains('uo_series', $tables);
        $this->assertContains('uo_pool', $tables);
        $this->assertContains('uo_team', $tables);
        $this->assertContains('uo_game', $tables);
        $this->assertContains('uo_player', $tables);
    }

    public function testExportJsonIncludesEventMetadata(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('event', $decoded);
        $this->assertSame('HRN2026', $decoded['event']['season_id']);
        $this->assertArrayHasKey('exported_at', $decoded);
        $this->assertArrayHasKey('source_db_version', $decoded);
    }

    public function testExportJsonThrowsForNonexistentSeason(): void
    {
        $this->expectException(EventSnapshotException::class);
        EventSnapshotExportJson('NONEXISTENT_SEASON_999');
    }

    public function testExportJsonThrowsForUnauthorizedUser(): void
    {
        $_SESSION = [];

        $this->expectException(EventSnapshotException::class);
        EventSnapshotExportJson('HRN2026');
    }

    // ── Import error tests ─────────────────────────────────────────────────

    public function testImportJsonThrowsForMissingFile(): void
    {
        $this->expectException(EventSnapshotException::class);
        EventSnapshotImportJson('/nonexistent/path/to/file.json');
    }

    public function testImportJsonThrowsForInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_bad_') . '.json';
        file_put_contents($tmp, 'this is not json');
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForXmlContent(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_xml_') . '.xml';
        file_put_contents($tmp, '<?xml version="1.0"?><root/>');
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForXmlWithBom(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_bom_') . '.xml';
        file_put_contents($tmp, "\xEF\xBB\xBF<?xml version=\"1.0\"?><root/>");
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForWrongFormat(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_fmt_') . '.json';
        file_put_contents($tmp, json_encode(['format' => 'unknown', 'version' => 2, 'tables' => []]));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForWrongVersion(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_ver_') . '.json';
        file_put_contents($tmp, json_encode([
            'format' => 'ultiorganizer.event-snapshot',
            'version' => 999,
            'tables' => [],
        ]));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForUnknownMode(): void
    {
        $tmp = $this->exportHrn2026ToTempFile();
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp, '', 'invalid_mode');
        } finally {
            @unlink($tmp);
        }
    }

    // ── Roundtrip import tests ─────────────────────────────────────────────

    public function testImportJsonNewModeCreatesNewSeason(): void
    {
        $tmp = $this->exportHrn2026ToTempFile();
        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');

            $this->assertIsArray($result);
            $this->assertArrayHasKey('season_id', $result);
            $this->assertArrayHasKey('warnings', $result);

            $importedSeasonId = $result['season_id'];
            $this->assertNotEmpty($importedSeasonId);
            $this->assertNotSame('HRN2026', $importedSeasonId);

            $this->assertTrue((bool) SeasonExists($importedSeasonId));

            $seriesCount = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_series WHERE season='" . DBEscapeString($importedSeasonId) . "'");
            $this->assertGreaterThan(0, $seriesCount);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }

    public function testImportJsonReplaceModeUpdatesExistingSeason(): void
    {
        $targetId = 'RPL' . substr(uniqid(), -6);
        $tid = DBEscapeString($targetId);
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, endtime) VALUES ('$tid', 'Replace Target', '2026-01-01 00:00:00', '2026-01-02 00:00:00')");

        $tmp = $this->exportHrn2026ToTempFile();
        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, $targetId, 'replace');

            $this->assertIsArray($result);
            $this->assertSame($targetId, $result['season_id']);
            $importedSeasonId = $result['season_id'];

            $seriesCount = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_series WHERE season='$tid'");
            $this->assertGreaterThan(0, $seriesCount);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            } else {
                DBQuery("DELETE FROM uo_season WHERE season_id='$tid'");
            }
        }
    }

    public function testImportJsonThrowsForReplaceModeWithoutAdminRights(): void
    {
        // Export while still admin
        $tmp = $this->exportHrn2026ToTempFile();
        // Strip admin rights before the import call
        $_SESSION = [];
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp, 'HRN2026', 'replace');
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForReplaceModeWithMissingTarget(): void
    {
        $tmp = $this->exportHrn2026ToTempFile();
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp, 'NONEXISTENT_SEASON_999', 'replace');
        } finally {
            @unlink($tmp);
        }
    }

    // ── Import with URLs (covers importUrls + validateUrlOwner) ──────────

    public function testImportJsonWithUrlsImportsUrls(): void
    {
        // Add a URL to team 300 before exporting so the snapshot has uo_urls
        DBQuery("INSERT INTO uo_urls (owner, owner_id, type, name, url) VALUES ('team', 300, 'website', 'Test Site', 'https://example.com')");
        $urlId = (int) DBQueryToValue("SELECT url_id FROM uo_urls WHERE owner='team' AND owner_id=300 ORDER BY url_id DESC LIMIT 1");
        $tmp = $this->exportHrn2026ToTempFile();
        // Clean up the URL we added
        DBQuery("DELETE FROM uo_urls WHERE url_id=$urlId");

        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $this->assertNotEmpty($importedSeasonId);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }

    public function testImportJsonThrowsForSnapshotWithInvalidUrlOwner(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        // Add URL with invalid owner type
        $data['tables']['uo_urls'] = [
            ['url_id' => 9999, 'owner' => 'invalid_owner', 'owner_id' => 300, 'type' => 'website', 'name' => 'Bad', 'url' => 'https://bad.example.com', 'publisher_id' => null],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_url_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp, '', 'new');
        } finally {
            @unlink($tmp);
        }
    }

    // ── Import with comments (covers importComments + validateCommentOwner) ──

    public function testImportJsonWithSeasonCommentImportsComment(): void
    {
        // Add a comment for the season
        DBQuery("INSERT INTO uo_comment (type, id, comment) VALUES (1, 'HRN2026', 'Test comment for export')");
        $tmp = $this->exportHrn2026ToTempFile();
        DBQuery("DELETE FROM uo_comment WHERE type=1 AND id='HRN2026'");

        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $this->assertNotEmpty($importedSeasonId);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
                // Also remove comments for the imported season
                DBQuery("DELETE FROM uo_comment WHERE type=1 AND id='" . DBEscapeString($importedSeasonId) . "'");
            }
        }
    }

    public function testImportJsonThrowsForSnapshotWithInvalidCommentType(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        // Add comment with invalid type
        $data['tables']['uo_comment'] = [
            ['type' => 999, 'id' => 'HRN2026', 'comment' => 'bad comment'],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_com_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp, '', 'new');
        } finally {
            @unlink($tmp);
        }
    }

    // ── Import with team profiles (covers importTeamProfiles) ─────────────

    public function testImportJsonWithTeamProfilesImportsProfiles(): void
    {
        // Insert a team profile for team 300
        DBQuery("INSERT INTO uo_team_profile (team_id, captain) VALUES (300, 'Ari Ace') ON DUPLICATE KEY UPDATE captain='Ari Ace'");
        $tmp = $this->exportHrn2026ToTempFile();
        DBQuery("DELETE FROM uo_team_profile WHERE team_id=300");

        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $this->assertNotEmpty($importedSeasonId);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }

    // ── Snapshot validation edge cases ────────────────────────────────────

    public function testImportJsonThrowsForMissingRequiredTable(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        unset($data['tables']['uo_season']);
        $tmp = tempnam(sys_get_temp_dir(), 'uo_tbl_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForMultipleSeasonRows(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_season'][] = $data['tables']['uo_season'][0];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_multi_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonThrowsForInvalidTableRow(): void
    {
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_series'][] = 'not an array';
        $tmp = tempnam(sys_get_temp_dir(), 'uo_row_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportJson($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportJsonSkipsUnknownColumnWithWarning(): void
    {
        // Columns the current schema does not know about are dropped and reported
        // as warnings (see EventSnapshotService::dropObsoleteSnapshotColumns),
        // so cross-version snapshots import instead of hard-failing.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_season'][0]['nonexistent_column_xyz'] = 'bad';
        $tmp = tempnam(sys_get_temp_dir(), 'uo_col_') . '.json';
        file_put_contents($tmp, json_encode($data));
        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');

            $this->assertArrayHasKey('warnings', $result);
            $importedSeasonId = $result['season_id'];
            $this->assertStringContainsString(
                'nonexistent_column_xyz',
                implode("\n", $result['warnings']),
            );
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }
}
