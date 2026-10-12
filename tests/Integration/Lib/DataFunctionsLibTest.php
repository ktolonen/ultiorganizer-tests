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
        // The import makes the importing admin an administrator of the new event and its teams; those
        // role rows would otherwise leak into every later test that logs in as admin.
        DBQuery("DELETE FROM uo_userproperties WHERE (name='editseason' AND value='$sid') OR value='seasonadmin:$sid'
            OR value IN (SELECT CONCAT('teamadmin:', t.team_id) FROM uo_team t JOIN uo_series s ON t.series=s.series_id WHERE s.season='$sid')");
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

    // ── EventSnapshotImportInfo tests ─────────────────────────────────────

    public function testImportInfoReturnsSeasonMetadata(): void
    {
        $tmp = $this->exportHrn2026ToTempFile();
        try {
            $info = EventSnapshotImportInfo($tmp);
            $this->assertIsArray($info);
            $this->assertSame('HRN2026', $info['season_id']);
            $this->assertArrayHasKey('name', $info);
            $this->assertArrayHasKey('exists', $info);
            $this->assertArrayHasKey('warnings', $info);
            $this->assertTrue($info['exists']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoWrapsValidationExceptionWithVersionDetails(): void
    {
        // A file with wrong format triggers validateSnapshot → EventSnapshotException
        // wrapped with version details via the catch block in importInfo (lines 111-112).
        $tmp = tempnam(sys_get_temp_dir(), 'uo_bad_') . '.json';
        file_put_contents($tmp, json_encode([
            'format' => 'wrong_format',
            'version' => 1,
            'source_db_version' => 1,
            'tables' => [],
        ]));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoThrowsForMissingTablesKey(): void
    {
        // No 'tables' key in snapshot → validateSnapshot throws at the tables check.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        unset($data['tables']);
        $tmp = tempnam(sys_get_temp_dir(), 'uo_notbl_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoThrowsForUnknownTableName(): void
    {
        // Adding an extra table not in the manifest → validateSnapshot throws.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_unknown_xyzzy'] = [];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_unk_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoThrowsForNonArrayTable(): void
    {
        // Setting a table to a scalar instead of array → validateSnapshot throws.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_season'] = 'not an array';
        $tmp = tempnam(sys_get_temp_dir(), 'uo_nta_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoThrowsForRowMissingRequiredPkField(): void
    {
        // A row without the required PK field triggers the PK check at line 513.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        $data['tables']['uo_series'][] = ['name' => 'No PK Field'];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_pk_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportInfoThrowsForDuplicatePrimaryKey(): void
    {
        // Two uo_series rows with the same series_id → duplicate key exception.
        $json = EventSnapshotExportJson('HRN2026');
        $data = json_decode($json, true);
        if (!empty($data['tables']['uo_series'])) {
            $data['tables']['uo_series'][] = $data['tables']['uo_series'][0];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'uo_dup_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            $this->expectException(EventSnapshotException::class);
            EventSnapshotImportInfo($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── Export with movingtime rows covers collectEventIds lines 302-303 ───

    public function testExportIncludesMovingtimeLocations(): void
    {
        DBQuery("INSERT INTO uo_movingtime (season, fromlocation, fromfield, tolocation, tofield, time)
                 VALUES ('HRN2026', 400, '1', 400, '2', 5)");
        try {
            $json = EventSnapshotExportJson('HRN2026');
            $data = json_decode($json, true);
            $this->assertIsArray($data['tables']['uo_movingtime']);
            $this->assertCount(1, $data['tables']['uo_movingtime']);
        } finally {
            DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026'");
        }
    }

    // ── Import movingtime row covers importMovingTimes (lines 1001-1004) ───

    public function testImportJsonWithMovingtimeImportsRecord(): void
    {
        DBQuery("INSERT INTO uo_movingtime (season, fromlocation, fromfield, tolocation, tofield, time)
                 VALUES ('HRN2026', 400, '1', 400, '2', 5)");
        $tmp = $this->exportHrn2026ToTempFile();
        DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026'");
        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $this->assertNotEmpty($importedSeasonId);
            $count = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_movingtime WHERE season='" . DBEscapeString($importedSeasonId) . "'"
            );
            $this->assertSame(1, $count);
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
            DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026'");
        }
    }

    // ── Rich roundtrip: every child table the baseline fixture leaves empty ─

    /** Rows added to HRN2026 so the snapshot carries data in each optional table. */
    private function addRichEventData(): void
    {
        DBQuery("INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, played, series, type, follower)
                 VALUES (201, 'Pool B', 'B', 1, 0, 0, 100, 1, 200)");
        DBQuery("INSERT INTO uo_moveteams (frompool, topool, fromplacing, torank, ismoved) VALUES (200, 201, 1, 1, 0)");
        DBQuery("INSERT INTO uo_specialranking (frompool, fromplacing, torank) VALUES (200, 2, 1)");
        foreach ([300, 301] as $team) {
            foreach ([1009, 1010, 1011, 1012, 1013] as $category) {
                DBQuery("INSERT INTO uo_spirit_score (game_id, team_id, category_id, value) VALUES (700, $team, $category, 3)");
            }
        }
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense)
                 VALUES (700, 1, 800, 120, 0, 1, 1), (700, 2, 801, 300, 1, 1, 1)");
        DBQuery("INSERT INTO uo_timeout (game, num, time, ishome) VALUES (700, 1, 100, 1)");
        DBQuery("INSERT INTO uo_spirit_timeout (game, num, time, ishome) VALUES (700, 1, 200, 0)");
        DBQuery("INSERT INTO uo_season_round (round_id, season, series, round_no, name) VALUES (9001, 'HRN2026', 100, 1, 'Round one')");
        DBQuery("INSERT INTO uo_season_points (round_id, team_id, points) VALUES (9001, 300, 5), (9001, 301, 3)");
        DBQuery("INSERT INTO uo_team_final_standing (season, series, team_id, standing, disqualified)
                 VALUES ('HRN2026', 100, 300, 1, 0), ('HRN2026', 100, 301, 2, 0)");
        DBQuery("INSERT INTO uo_urls (owner, owner_id, type, name, url) VALUES
                 ('ultiorganizer', 'HRN2026', 'link', 'Event link', 'https://example.com/event'),
                 ('series', '100', 'link', 'Series link', 'https://example.com/series'),
                 ('pool', '200', 'link', 'Pool link', 'https://example.com/pool'),
                 ('team', '300', 'link', 'Team link', 'https://example.com/team'),
                 ('game', '700', 'link', 'Game link', 'https://example.com/game')");
    }

    private function removeRichEventData(): void
    {
        DBQuery("DELETE FROM uo_urls WHERE url LIKE 'https://example.com/%' AND name LIKE '% link'");
        DBQuery("DELETE FROM uo_team_final_standing WHERE season='HRN2026'");
        DBQuery("DELETE FROM uo_season_round WHERE season='HRN2026'");
        DBQuery("DELETE FROM uo_spirit_timeout WHERE game=700");
        DBQuery("DELETE FROM uo_timeout WHERE game=700");
        DBQuery("DELETE FROM uo_defense WHERE game=700");
        DBQuery("DELETE FROM uo_spirit_score WHERE game_id=700");
        DBQuery("DELETE FROM uo_specialranking WHERE frompool=200");
        DBQuery("DELETE FROM uo_moveteams WHERE frompool=200");
        DBQuery("DELETE FROM uo_pool WHERE pool_id=201");
    }

    private function countInEvent(string $sql, string $seasonId): int
    {
        return (int) DBQueryToValueUncached(str_replace(':season', "'" . DBEscapeString($seasonId) . "'", $sql));
    }

    public function testImportJsonNewModeCarriesGameChildRowsRoundsStandingsMovesAndLinks(): void
    {
        $this->addRichEventData();
        $tmp = $this->exportHrn2026ToTempFile();
        $this->removeRichEventData();
        $importedSeasonId = null;
        try {
            $data = json_decode((string) file_get_contents($tmp), true);
            foreach (['uo_spirit_score' => 10, 'uo_defense' => 2, 'uo_gameevent' => 3, 'uo_timeout' => 1,
                      'uo_spirit_timeout' => 1, 'uo_season_round' => 1, 'uo_season_points' => 2,
                      'uo_team_final_standing' => 2, 'uo_moveteams' => 1, 'uo_specialranking' => 1, 'uo_urls' => 5] as $table => $expected) {
                $this->assertCount($expected, $data['tables'][$table], "precondition: snapshot has $table rows");
            }

            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $this->assertNotSame('HRN2026', $importedSeasonId);

            $gameJoin = "FROM %s x JOIN uo_game g ON g.game_id=x.%s JOIN uo_team t ON t.team_id=g.hometeam
                         JOIN uo_series se ON se.series_id=t.series WHERE se.season=:season";
            $this->assertSame(10, $this->countInEvent("SELECT COUNT(*) " . sprintf($gameJoin, 'uo_spirit_score', 'game_id'), $importedSeasonId));
            $this->assertSame(2, $this->countInEvent("SELECT COUNT(*) " . sprintf($gameJoin, 'uo_defense', 'game'), $importedSeasonId));
            $this->assertSame(3, $this->countInEvent("SELECT COUNT(*) " . sprintf($gameJoin, 'uo_gameevent', 'game'), $importedSeasonId));
            $this->assertSame(1, $this->countInEvent("SELECT COUNT(*) " . sprintf($gameJoin, 'uo_timeout', 'game'), $importedSeasonId));
            $this->assertSame(1, $this->countInEvent("SELECT COUNT(*) " . sprintf($gameJoin, 'uo_spirit_timeout', 'game'), $importedSeasonId));

            // The imported defences keep their values and point at the imported players.
            $this->assertSame(
                [['num' => 1, 'time' => 120, 'iscallahan' => 0, 'name' => 'Ari'], ['num' => 2, 'time' => 300, 'iscallahan' => 1, 'name' => 'Bea']],
                array_map(
                    fn($r) => ['num' => (int) $r['num'], 'time' => (int) $r['time'], 'iscallahan' => (int) $r['iscallahan'], 'name' => $r['firstname']],
                    DBQueryToArrayUncached(
                        "SELECT d.num, d.time, d.iscallahan, p.firstname FROM uo_defense d
                         JOIN uo_player p ON p.player_id=d.author
                         JOIN uo_team t ON t.team_id=p.team JOIN uo_series se ON se.series_id=t.series
                         WHERE se.season='" . DBEscapeString($importedSeasonId) . "' ORDER BY d.num"
                    )
                )
            );

            $round = DBQueryToRowUncached("SELECT round_id, name, round_no FROM uo_season_round WHERE season='" . DBEscapeString($importedSeasonId) . "'");
            $this->assertSame('Round one', $round['name']);
            $points = DBQueryToArrayUncached(
                "SELECT t.name, sp.points FROM uo_season_points sp JOIN uo_team t ON t.team_id=sp.team_id
                 WHERE sp.round_id=" . (int) $round['round_id'] . " ORDER BY t.name"
            );
            $this->assertSame([['Helsinki Heat', '5'], ['Tampere Tempest', '3']], array_map(fn($r) => [$r['name'], (string) $r['points']], $points));

            $standings = DBQueryToArrayUncached(
                "SELECT t.name, f.standing FROM uo_team_final_standing f JOIN uo_team t ON t.team_id=f.team_id
                 WHERE f.season='" . DBEscapeString($importedSeasonId) . "' ORDER BY f.standing"
            );
            $this->assertSame([['Helsinki Heat', '1'], ['Tampere Tempest', '2']], array_map(fn($r) => [$r['name'], (string) $r['standing']], $standings));

            // Pools: the move rule and the follower link are remapped to the imported pools.
            $pools = [];
            foreach (DBQueryToArrayUncached(
                "SELECT p.pool_id, p.name, p.follower FROM uo_pool p JOIN uo_series se ON se.series_id=p.series
                 WHERE se.season='" . DBEscapeString($importedSeasonId) . "'"
            ) as $row) {
                $pools[$row['name']] = $row;
            }
            $this->assertEquals($pools['Pool A']['pool_id'], $pools['Pool B']['follower']);
            $this->assertNull($pools['Pool A']['follower']);
            $move = DBQueryToRowUncached("SELECT frompool, topool FROM uo_moveteams WHERE frompool=" . (int) $pools['Pool A']['pool_id']);
            $this->assertEquals($pools['Pool B']['pool_id'], $move['topool']);
            $this->assertEquals(1, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_specialranking WHERE frompool=" . (int) $pools['Pool A']['pool_id']));

            // Links follow their owners to the imported ids.
            $this->assertEquals(1, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_urls WHERE owner='ultiorganizer' AND owner_id='" . DBEscapeString($importedSeasonId) . "' AND name='Event link'"));
            $this->assertEquals(1, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_urls u JOIN uo_pool p ON p.pool_id=u.owner_id WHERE u.owner='pool' AND p.pool_id=" . (int) $pools['Pool A']['pool_id']));
        } finally {
            @unlink($tmp);
            $this->removeRichEventData();
            if ($importedSeasonId !== null) {
                $sid = DBEscapeString($importedSeasonId);
                DBQuery("DELETE FROM uo_urls WHERE name LIKE '% link' AND url LIKE 'https://example.com/%'");
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }

    // ── Snapshot validation: each refusal names its reason ────────────────

    /** Export HRN2026, let $mutate edit the decoded snapshot, import it and return the refusal message. */
    private function importMutatedSnapshotMessage(callable $mutate, string $mode = 'new'): string
    {
        $data = json_decode(EventSnapshotExportJson('HRN2026'), true);
        $mutate($data);
        $tmp = tempnam(sys_get_temp_dir(), 'uo_mut_') . '.json';
        file_put_contents($tmp, json_encode($data));
        try {
            EventSnapshotImportJson($tmp, '', $mode);
        } catch (EventSnapshotException $e) {
            // Refusals carry a trailing "Import file snapshot version: ..." detail; the reason comes first.
            return preg_replace('/ Import file snapshot version: .*$/', '', $e->getMessage());
        } finally {
            @unlink($tmp);
        }
        $this->fail('The import was expected to be refused.');
    }

    public function testImportJsonRefusesPlayerWithoutProfileInSnapshot(): void
    {
        $this->assertSame(
            'Every imported player must have a player profile in the event snapshot.',
            $this->importMutatedSnapshotMessage(function (&$data) {
                $data['tables']['uo_player'][0]['profile_id'] = null;
            })
        );
    }

    public function testImportJsonRefusesMediaEventWithoutItsLink(): void
    {
        $this->assertSame(
            'The event snapshot has a media event without its media link.',
            $this->importMutatedSnapshotMessage(function (&$data) {
                $data['tables']['uo_gameevent'][] = ['game' => 700, 'num' => 9, 'time' => 10, 'type' => 'media', 'ishome' => 0, 'info' => '987654'];
            })
        );
    }

    public function testImportJsonRefusesGameResponsibleTeamOutsideTheSnapshot(): void
    {
        $this->assertSame(
            'Missing required parent row for uo_game.respteam.',
            $this->importMutatedSnapshotMessage(function (&$data) {
                $data['tables']['uo_game'][0]['respteam'] = 987654;
            })
        );
    }

    public function testImportJsonRefusesLinksWithoutAnOwnerInTheSnapshot(): void
    {
        $url = ['url_id' => 9001, 'type' => 'link', 'name' => 'Orphan', 'url' => 'https://example.com/o'];
        $missingParent = 'Missing required parent row for uo_urls.owner_id.';
        foreach ([
            ['ultiorganizer', 'NOT-THIS-EVENT', $missingParent],
            ['series', '987654', $missingParent],
            ['pool', '987654', $missingParent],
            ['team', '987654', $missingParent],
            ['game', '987654', $missingParent],
            ['series', null, $missingParent],
            ['planet', '1', 'Unsupported event snapshot link owner.'],
        ] as [$owner, $ownerId, $expected]) {
            $this->assertSame($expected, $this->importMutatedSnapshotMessage(function (&$data) use ($url, $owner, $ownerId) {
                $data['tables']['uo_urls'][] = $url + ['owner' => $owner, 'owner_id' => $ownerId];
            }), "owner $owner / " . var_export($ownerId, true));
        }
    }

    public function testImportJsonRefusesCommentsWithoutAnOwnerInTheSnapshot(): void
    {
        $missingParent = 'Missing required parent row for uo_comment.id.';
        foreach ([
            [1, 'NOT-THIS-EVENT', $missingParent],
            [2, '987654', $missingParent],
            [3, '987654', $missingParent],
            [4, '987654', $missingParent],
            [5, '987654', $missingParent],
            [6, '987654', $missingParent],
            [3, '', $missingParent],
            [9, '1', 'Unsupported event snapshot comment type.'],
        ] as [$type, $id, $expected]) {
            $this->assertSame($expected, $this->importMutatedSnapshotMessage(function (&$data) use ($type, $id) {
                $data['tables']['uo_comment'][] = ['type' => $type, 'id' => $id, 'comment' => 'Orphan'];
            }), "comment type $type / '$id'");
        }
    }

    public function testImportJsonRefusesConflictingPlayerProfileIdentities(): void
    {
        $conflict = 'Conflicting player profile identities in the event snapshot.';
        $this->assertSame($conflict, $this->importMutatedSnapshotMessage(function (&$data) {
            $data['tables']['uo_player_profile'][] = ['profile_id' => 9101, 'firstname' => 'Una', 'lastname' => 'One', 'accreditation_id' => 'DUP-ACC'];
            $data['tables']['uo_player_profile'][] = ['profile_id' => 9102, 'firstname' => 'Duo', 'lastname' => 'Two', 'accreditation_id' => ' DUP-ACC '];
        }), 'same accreditation id on two profiles');
        $this->assertSame($conflict, $this->importMutatedSnapshotMessage(function (&$data) {
            $data['tables']['uo_player_profile'][] = ['profile_id' => 9103, 'firstname' => 'Same', 'lastname' => 'Person', 'birthdate' => '1990-05-05 00:00:00'];
            $data['tables']['uo_player_profile'][] = ['profile_id' => 9104, 'firstname' => 'Same', 'lastname' => 'Person', 'birthdate' => '1990-05-05'];
        }), 'same name and birth date on two profiles');
    }

    public function testImportJsonRefusesAmbiguousExistingPlayerProfiles(): void
    {
        DBQuery("INSERT INTO uo_player_profile (firstname, lastname, accreditation_id) VALUES ('Amb', 'One', 'AMB-ACC'), ('Amb', 'Two', 'AMB-ACC')");
        try {
            $this->assertSame(
                'Ambiguous player profile match for Amb Three.',
                $this->importMutatedSnapshotMessage(function (&$data) {
                    $data['tables']['uo_player_profile'][] = ['profile_id' => 9105, 'firstname' => 'Amb', 'lastname' => 'Three', 'accreditation_id' => 'AMB-ACC'];
                })
            );
        } finally {
            DBQuery("DELETE FROM uo_player_profile WHERE accreditation_id='AMB-ACC'");
        }
        DBQuery("INSERT INTO uo_player_profile (firstname, lastname, birthdate) VALUES ('Nam', 'Dup', '1991-02-03'), ('Nam', 'Dup', '1991-02-03')");
        try {
            $this->assertSame(
                'Ambiguous player profile match for Nam Dup.',
                $this->importMutatedSnapshotMessage(function (&$data) {
                    $data['tables']['uo_player_profile'][] = ['profile_id' => 9106, 'firstname' => 'Nam', 'lastname' => 'Dup', 'birthdate' => '1991-02-03'];
                })
            );
        } finally {
            DBQuery("DELETE FROM uo_player_profile WHERE firstname='Nam' AND lastname='Dup'");
        }
    }

    public function testImportJsonRefusesNewModeImportWithoutAdminRights(): void
    {
        $tmp = $this->exportHrn2026ToTempFile();
        $_SESSION = [];
        try {
            $this->expectException(EventSnapshotException::class);
            $this->expectExceptionMessage('Insufficient rights to import data');
            EventSnapshotImportJson($tmp, '', 'new');
        } finally {
            @unlink($tmp);
        }
    }

    // ── Player profiles: export completes them, import reuses known ones ──

    public function testImportJsonReusesPlayerProfilesByAccreditationIdAndSynthesizesMissingOnes(): void
    {
        DBQuery("INSERT INTO uo_player_profile (profile_id, firstname, lastname, accreditation_id) VALUES (99701, 'Ari', 'Ace', 'DATA-ACC-1')");
        // 800 has the profile; 801 only repeats its accreditation id; 802 and 803 share one without any profile.
        DBQuery("UPDATE uo_player SET profile_id=99701, accreditation_id='DATA-ACC-1' WHERE player_id=800");
        DBQuery("UPDATE uo_player SET accreditation_id='DATA-ACC-1' WHERE player_id=801");
        DBQuery("UPDATE uo_player SET accreditation_id='DATA-ACC-2' WHERE player_id IN (802, 803)");
        $importedSeasonId = null;
        $tmp = null;
        try {
            $data = json_decode(EventSnapshotExportJson('HRN2026'), true);
            $profileOf = [];
            foreach ($data['tables']['uo_player'] as $player) {
                $profileOf[(int) $player['player_id']] = (int) $player['profile_id'];
            }
            $this->assertSame(99701, $profileOf[800]);
            $this->assertSame(99701, $profileOf[801], 'a player repeating the accreditation id shares the exported profile');
            $this->assertLessThan(0, $profileOf[802], 'no profile: a synthetic one is created');
            $this->assertSame($profileOf[802], $profileOf[803], 'players with the same accreditation id share one synthetic profile');

            $tmp = tempnam(sys_get_temp_dir(), 'uo_prof_') . '.json';
            file_put_contents($tmp, json_encode($data));
            $profilesBefore = (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_player_profile");

            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];

            // The known profile is reused, so only the one synthetic profile (DATA-ACC-2) is new.
            $this->assertSame($profilesBefore + 1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_player_profile"));
            $imported = DBQueryToArrayUncached(
                "SELECT p.firstname, p.profile_id FROM uo_player p JOIN uo_team t ON t.team_id=p.team
                 JOIN uo_series se ON se.series_id=t.series WHERE se.season='" . DBEscapeString($importedSeasonId) . "' ORDER BY p.firstname"
            );
            $byName = array_column($imported, 'profile_id', 'firstname');
            $this->assertEquals(99701, $byName['Ari']);
            $this->assertEquals(99701, $byName['Bea']);
            $this->assertEquals($byName['Nia'], $byName['Timo']);
            $this->assertNotEquals(99701, $byName['Nia']);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
            DBQuery("UPDATE uo_player SET profile_id=NULL, accreditation_id=NULL WHERE player_id IN (800, 801, 802, 803)");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=99701 OR accreditation_id IN ('DATA-ACC-1', 'DATA-ACC-2')");
        }
    }

    // ── Import sanitising and replace-mode side effects ───────────────────

    public function testImportJsonClearsDanglingSoftReferencesAndDropsOrphanRowsWithWarnings(): void
    {
        $data = json_decode(EventSnapshotExportJson('HRN2026'), true);
        $teamCount = count($data['tables']['uo_team']);
        $teamPoolCount = count($data['tables']['uo_team_pool']);
        $data['tables']['uo_team'][0]['pool'] = 987654;
        $data['tables']['uo_team_pool'][] = ['team' => 987654, 'pool' => $data['tables']['uo_pool'][0]['pool_id']];
        $data['tables']['uo_spirit_score'][] = ['game_id' => 700, 'team_id' => 987654, 'category_id' => 1009, 'value' => 2];
        $tmp = tempnam(sys_get_temp_dir(), 'uo_san_') . '.json';
        file_put_contents($tmp, json_encode($data));
        $importedSeasonId = null;
        try {
            $result = EventSnapshotImportJson($tmp, '', 'new');
            $importedSeasonId = $result['season_id'];
            $sid = DBEscapeString($importedSeasonId);

            $this->assertContains('Cleared 1 team current-pool reference(s) pointing outside the event.', $result['warnings']);
            $this->assertContains('Dropped 1 team pool placement row(s) referencing data outside the event.', $result['warnings']);
            $this->assertContains('Dropped 1 spirit score row(s) referencing data outside the event.', $result['warnings']);
            // Nothing else is reported as dangling, and the rows that were fine all came through.
            $this->assertCount(3, array_filter($result['warnings'], fn($w) => str_contains($w, 'outside the event')));
            $this->assertEquals($teamCount, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team t JOIN uo_series s ON s.series_id=t.series WHERE s.season='$sid'"));
            $this->assertEquals($teamPoolCount, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team_pool tp JOIN uo_team t ON t.team_id=tp.team JOIN uo_series s ON s.series_id=t.series WHERE s.season='$sid'"));
        } finally {
            @unlink($tmp);
            if ($importedSeasonId !== null) {
                $this->deleteImportedSeason($importedSeasonId);
            }
        }
    }

    public function testImportJsonReplaceModeWarnsAboutLostDelegatedAdminsAndRemovesOrphanedProfiles(): void
    {
        $targetId = 'RPL' . substr(uniqid(), -6);
        $tid = DBEscapeString($targetId);
        DBQuery("INSERT INTO uo_season (season_id, name, starttime, endtime) VALUES ('$tid', 'Replace With Roles', '2026-01-01 00:00:00', '2026-01-02 00:00:00')");
        DBQuery("INSERT INTO uo_series (series_id, season, name, valid) VALUES (99150, '$tid', 'Old Open', 1)");
        DBQuery("INSERT INTO uo_team (team_id, series, name, valid) VALUES (99350, 99150, 'Old Team', 1)");
        DBQuery("INSERT INTO uo_player_profile (profile_id, firstname, lastname) VALUES (99750, 'Old', 'Player')");
        DBQuery("INSERT INTO uo_player (player_id, team, profile_id, firstname, lastname) VALUES (99850, 99350, 99750, 'Old', 'Player')");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('ctest_delegate', 'userrole', 'teamadmin:99350'), ('ctest_season', 'userrole', 'seasonadmin:$tid')");
        $tmp = $this->exportHrn2026ToTempFile();
        try {
            $result = EventSnapshotImportJson($tmp, $targetId, 'replace');

            $this->assertSame($targetId, $result['season_id']);
            $this->assertContains(
                'Admin rights delegated to specific divisions, teams, games, or reservations are not migrated by a replace import. Re-grant access for: ctest_delegate.',
                $result['warnings'],
                'only the entity-scoped admin is listed; the season admin keeps working'
            );
            $this->assertContains('Removed 1 player profile(s) the replace import left without any player.', $result['warnings']);
            $this->assertEquals(0, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_player_profile WHERE profile_id=99750"));
            $this->assertEquals(0, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_team WHERE team_id=99350"));
            $this->assertEquals(1, DBQueryToValueUncached("SELECT COUNT(*) FROM uo_userproperties WHERE userid='ctest_season'"));
        } finally {
            @unlink($tmp);
            DBQuery("DELETE FROM uo_userproperties WHERE userid IN ('ctest_delegate', 'ctest_season')");
            DBQuery("DELETE FROM uo_player_profile WHERE profile_id=99750");
            $this->deleteImportedSeason($targetId);
        }
    }
}
