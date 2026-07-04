<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class DatabaseLibTest extends TestCase
{
    // Fixture: location 400 ('Harness Field Complex'), player 800 ('Ari Ace', team=300).
    // Tests exercise DB utility functions in lib/database.php directly.

    private ?int $insertedUrlId = null;

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

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // database_with_common loads database.php + common.functions.php (needed for DBSetRow).
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        // Load extra libs to enable the caching path in DBQueryCacheable.
        LegacyApp::requireTopLevelLib('configuration.functions.php');
        LegacyApp::requireTopLevelLib('persistent-cache.functions.php');

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
    }

    protected function tearDown(): void
    {
        if ($this->insertedUrlId !== null) {
            DBQuery(sprintf("DELETE FROM uo_urls WHERE url_id=%d", $this->insertedUrlId));
            $this->insertedUrlId = null;
        }
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- DBEscapeString ---

    public function testDBEscapeStringReturnsUnchangedSafeString(): void
    {
        $this->assertSame('hello', DBEscapeString('hello'));
    }

    public function testDBEscapeStringEscapesSingleQuote(): void
    {
        $escaped = DBEscapeString("it's");
        $this->assertStringContainsString("\'", $escaped);
    }

    public function testDBEscapeStringHandlesNull(): void
    {
        $this->assertSame('', DBEscapeString(null));
    }

    public function testDBEscapeStringHandlesInteger(): void
    {
        $this->assertSame('42', DBEscapeString(42));
    }

    // --- DBUserErrorMessage / DBError ---

    public function testDBUserErrorMessageReturnsString(): void
    {
        // Fixed constant message, not derived from any error state.
        $this->assertSame(
            'Service is temporarily unavailable. Please try again shortly. If the problem persists, please contact the event organizer.',
            DBUserErrorMessage(),
        );
    }

    public function testDBErrorReturnsEmptyStringWhenNoError(): void
    {
        DBQuery('SELECT 1');
        $this->assertSame('', DBError());
    }

    // --- DBShouldThrowExceptions / DBSetExceptionMode ---

    public function testDBShouldThrowExceptionsReturnsBool(): void
    {
        // Reads $GLOBALS['db_throw_exceptions'], unset by default (false) before any test
        // in this class calls DBSetExceptionMode().
        $this->assertFalse(DBShouldThrowExceptions());
    }

    public function testDBSetExceptionModeTogglesState(): void
    {
        $original = DBShouldThrowExceptions();
        DBSetExceptionMode(!$original);
        $this->assertSame(!$original, DBShouldThrowExceptions());
        DBSetExceptionMode($original);
        $this->assertSame($original, DBShouldThrowExceptions());
    }

    // --- DBQueryCacheable ---

    public function testDBQueryCacheableReturnsFalseWhenCacheDisabled(): void
    {
        $this->assertFalse(DBQueryCacheable('SELECT 1'));
    }

    // --- DBQuery ---

    public function testDBQueryReturnsResultForSelect(): void
    {
        $result = DBQuery('SELECT 1 AS val');
        $this->assertNotFalse($result);
    }

    // --- DBExecute ---

    public function testDBExecuteReturnsResultForSelect(): void
    {
        $result = DBExecute('SELECT 1 AS val');
        $this->assertNotFalse($result);
    }

    // --- DBReplaySqlLines ---

    public function testDBReplaySqlLinesSkipsCommentsAndEmptyLines(): void
    {
        DBReplaySqlLines(['-- comment', '', 'SELECT 1;']);
        $this->assertTrue(true);
    }

    // --- DBQueryToValue / DBQueryToValueUncached ---

    public function testDBQueryToValueReturnsSingleValue(): void
    {
        $val = DBQueryToValue("SELECT 42 AS n");
        $this->assertSame('42', $val);
    }

    public function testDBQueryToValueReturnsNullForNoRows(): void
    {
        $val = DBQueryToValue("SELECT firstname FROM uo_player WHERE player_id=999999");
        $this->assertNull($val);
    }

    public function testDBQueryToValueUncachedReturnsSingleValue(): void
    {
        $val = DBQueryToValueUncached("SELECT 'hello' AS s");
        $this->assertSame('hello', $val);
    }

    // --- DBQueryToArray / DBQueryToArrayUncached ---

    public function testDBQueryToArrayReturnsArray(): void
    {
        $result = DBQueryToArray("SELECT player_id FROM uo_player WHERE player_id=800");
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('800', $result[0]['player_id']);
    }

    public function testDBQueryToArrayWithDocastingConvertsIntegers(): void
    {
        $result = DBQueryToArray("SELECT player_id FROM uo_player WHERE player_id=800", true);
        $this->assertIsArray($result);
        $this->assertSame(800, $result[0]['player_id']);
    }

    public function testDBQueryToArrayReturnsEmptyForNoRows(): void
    {
        $result = DBQueryToArray("SELECT player_id FROM uo_player WHERE player_id=999999");
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDBQueryToArrayUncachedReturnsArray(): void
    {
        $result = DBQueryToArrayUncached("SELECT player_id FROM uo_player WHERE player_id=800");
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- DBQueryToRow / DBQueryToRowUncached ---

    public function testDBQueryToRowReturnsSingleRow(): void
    {
        $row = DBQueryToRow("SELECT firstname, lastname FROM uo_player WHERE player_id=800");
        $this->assertIsArray($row);
        $this->assertSame('Ari', $row['firstname']);
        $this->assertSame('Ace', $row['lastname']);
    }

    public function testDBQueryToRowReturnsNullForNoRows(): void
    {
        $row = DBQueryToRow("SELECT firstname FROM uo_player WHERE player_id=999999");
        $this->assertNull($row);
    }

    public function testDBQueryToRowWithDocastingConvertsTypes(): void
    {
        $row = DBQueryToRow("SELECT player_id FROM uo_player WHERE player_id=800", true);
        $this->assertIsArray($row);
        $this->assertSame(800, $row['player_id']);
    }

    public function testDBQueryToRowUncachedReturnsSingleRow(): void
    {
        $row = DBQueryToRowUncached("SELECT firstname FROM uo_player WHERE player_id=800");
        $this->assertIsArray($row);
        $this->assertSame('Ari', $row['firstname']);
    }

    // --- DBQueryRowCount / DBQueryRowCountUncached ---

    public function testDBQueryRowCountReturnsCountForFixturePlayers(): void
    {
        $count = (int) DBQueryRowCount("SELECT 1 FROM uo_player WHERE player_id=800");
        $this->assertSame(1, $count);
    }

    public function testDBQueryRowCountReturnsZeroForNoMatch(): void
    {
        $count = (int) DBQueryRowCount("SELECT 1 FROM uo_player WHERE player_id=999999");
        $this->assertSame(0, $count);
    }

    public function testDBQueryRowCountUncachedReturnsCount(): void
    {
        $count = (int) DBQueryRowCountUncached("SELECT 1 FROM uo_player WHERE player_id=800");
        $this->assertSame(1, $count);
    }

    // --- DBQueryInsert ---

    public function testDBQueryInsertReturnsNewId(): void
    {
        $id = (int) DBQueryInsert(
            "INSERT INTO uo_urls (owner, owner_id, type, url, name) VALUES ('player', 800, 'website', 'https://example.com', 'DbLibTest')"
        );
        $this->assertGreaterThan(0, $id);
        $this->insertedUrlId = $id;
    }

    // --- DBResourceToArray ---

    public function testDBResourceToArrayReturnsArray(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $rows = DBResourceToArray($result);
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('800', $rows[0]['player_id']);
    }

    // --- DBFetchAssoc ---

    public function testDBFetchAssocReturnsSingleRow(): void
    {
        $result = DBQuery("SELECT firstname, lastname FROM uo_player WHERE player_id=800");
        $row = DBFetchAssoc($result);
        $this->assertIsArray($row);
        $this->assertSame('Ari', $row['firstname']);
    }

    public function testDBFetchAssocWithDocastingReturnsTypedRow(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $row = DBFetchAssoc($result, true);
        $this->assertIsArray($row);
        $this->assertSame(800, $row['player_id']);
    }

    // --- DBFetchAllAssoc ---

    public function testDBFetchAllAssocReturnsAllRows(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id IN(800, 801) ORDER BY player_id");
        $rows = DBFetchAllAssoc($result);
        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    // --- DBCastArray ---

    public function testDBCastArrayConvertsIntColumn(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $row = mysqli_fetch_assoc($result);
        $casted = DBCastArray($result, $row);
        $this->assertSame(800, $casted['player_id']);
    }

    public function testDBCastArrayPreservesNull(): void
    {
        $result = DBQuery("SELECT profile_id FROM uo_player WHERE player_id=800");
        $row = mysqli_fetch_assoc($result);
        $casted = DBCastArray($result, $row);
        $this->assertNull($casted['profile_id']);
    }

    // --- DBSetRow ---

    public function testDBSetRowUpdatesAndRestoresLocationName(): void
    {
        $original = DBQueryToValue("SELECT name FROM uo_location WHERE id=400");
        DBSetRow('uo_location', ['name' => 'Updated Name'], 'id=400');
        $updated = DBQueryToValue("SELECT name FROM uo_location WHERE id=400");
        $this->assertSame('Updated Name', $updated);
        DBSetRow('uo_location', ['name' => $original], 'id=400');
    }

    // --- Prepared statements ---

    public function testDBPrepareExecuteAndFetchRow(): void
    {
        $stmt = DBPrepare("SELECT firstname FROM uo_player WHERE player_id=?");
        $this->assertNotFalse($stmt);
        $id = 800;
        DBStmtBindParam($stmt, 'i', $id);
        DBStmtExecute($stmt);
        $result = DBStmtGetResult($stmt);
        $row = mysqli_fetch_assoc($result);
        $this->assertSame('Ari', $row['firstname']);
        $this->assertSame('', DBStmtError($stmt));
        DBStmtClose($stmt);
    }

    // --- getDBVersion ---

    public function testGetDBVersionReturnsPositiveInteger(): void
    {
        $version = getDBVersion();
        $this->assertIsInt($version);
        $this->assertGreaterThan(0, $version);
    }

    // --- DB info functions ---

    public function testDBStatReturnsString(): void
    {
        // Counters (uptime, questions, etc.) change every run — assert the mysqli_stat format
        // instead of an exact value.
        $stat = DBStat();
        $this->assertStringStartsWith('Uptime: ', $stat);
        $this->assertStringContainsString('Threads: ', $stat);
        $this->assertStringContainsString('Questions: ', $stat);
    }

    public function testDBClientInfoReturnsString(): void
    {
        // mysqlnd version string; assert the driver name rather than the exact PHP patch version.
        $this->assertStringStartsWith('mysqlnd ', DBClientInfo());
    }

    public function testDBHostInfoReturnsString(): void
    {
        // Harness always connects to the docker-compose 'mariadb' service over TCP.
        $this->assertSame('mariadb via TCP/IP', DBHostInfo());
    }

    public function testDBServerInfoReturnsString(): void
    {
        // MariaDB 10.11.x per docker-compose.yml's pinned image tag; assert the major.minor
        // series and vendor rather than the exact patch/build suffix.
        $this->assertMatchesRegularExpression('/^10\.11\.\d+-MariaDB/', DBServerInfo());
    }

    // --- DBProtocolInfo ---

    public function testDBProtocolInfoReturnsInteger(): void
    {
        // The MySQL wire protocol version has been a stable constant (10) since MySQL 4.1.
        $this->assertSame(10, DBProtocolInfo());
    }

    // --- DBNumRows ---

    public function testDBNumRowsWithArrayReturnsCount(): void
    {
        $this->assertSame(2, DBNumRows(['a', 'b']));
    }

    public function testDBNumRowsWithEmptyArrayReturnsZero(): void
    {
        $this->assertSame(0, DBNumRows([]));
    }

    public function testDBNumRowsWithMysqliResultReturnsCount(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $this->assertSame(1, DBNumRows($result));
    }

    // --- DBFieldName ---

    public function testDBFieldNameReturnsColumnName(): void
    {
        $result = DBQuery("SELECT firstname FROM uo_player WHERE player_id=800");
        $this->assertSame('firstname', DBFieldName($result, 0));
    }

    // --- DBQueryToValueUncached with docasting ---

    public function testDBQueryToValueUncachedWithDocastingReturnsInt(): void
    {
        $val = DBQueryToValueUncached("SELECT player_id FROM uo_player WHERE player_id=800", true);
        $this->assertSame(800, $val);
    }

    // --- DBResourceToArray with array input ---

    public function testDBResourceToArrayWithEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], DBResourceToArray([]));
    }

    public function testDBResourceToArrayWithArrayOfRowsReturnsRows(): void
    {
        $input = [['firstname' => 'Ari'], ['firstname' => 'Ace']];
        $result = DBResourceToArray($input);
        $this->assertSame($input, $result);
    }

    public function testDBResourceToArrayWithDocastingOnResult(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $rows = DBResourceToArray($result, true);
        $this->assertSame(800, $rows[0]['player_id']);
    }

    // --- DBFetchAssoc with array input ---

    public function testDBFetchAssocWithArrayOfRowsReturnsFirstRow(): void
    {
        $rows = [['firstname' => 'Ari'], ['firstname' => 'Ace']];
        $row = DBFetchAssoc($rows);
        $this->assertSame(['firstname' => 'Ari'], $row);
    }

    public function testDBFetchAssocWithEmptyArrayReturnsNull(): void
    {
        $empty = [];
        $this->assertNull(DBFetchAssoc($empty));
    }

    public function testDBFetchAssocWithSingleArrayRowReturnsThatRow(): void
    {
        // When the array itself is a single row (not array of arrays), return it.
        $singleRow = ['firstname' => 'Ari'];
        $row = DBFetchAssoc($singleRow);
        $this->assertSame(['firstname' => 'Ari'], $row);
    }

    // --- DBFetchAllAssoc with array and docasting ---

    public function testDBFetchAllAssocWithArrayOfRowsReturnsAll(): void
    {
        $input = [['firstname' => 'Ari'], ['firstname' => 'Ace']];
        $this->assertSame($input, DBFetchAllAssoc($input));
    }

    public function testDBFetchAllAssocWithEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], DBFetchAllAssoc([]));
    }

    public function testDBFetchAllAssocWithDocastingConvertsTypes(): void
    {
        $result = DBQuery("SELECT player_id FROM uo_player WHERE player_id=800");
        $rows = DBFetchAllAssoc($result, true);
        $this->assertSame(800, $rows[0]['player_id']);
    }

    // --- DBCastArray with real (float) column ---

    public function testDBCastArrayConvertsRealColumn(): void
    {
        $result = DBQuery("SELECT lat FROM uo_location WHERE id=400");
        $row = mysqli_fetch_assoc($result);
        $casted = DBCastArray($result, $row);
        // Fixture location 400 has lat=60.1699; the cast must yield that float, not just any float.
        $this->assertIsFloat($casted['lat']);
        $this->assertEqualsWithDelta(60.1699, $casted['lat'], 0.00001);
    }

    // --- Caching path (DBQueryCacheable returns true) ---

    public function testDBQueryToValueWithCachingEnabledReturnsSameValue(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $val = DBQueryToValue("SELECT firstname FROM uo_player WHERE player_id=800");
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertSame('Ari', $val);
    }

    public function testDBQueryRowCountWithCachingEnabledReturnsCount(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $count = (int) DBQueryRowCount("SELECT 1 FROM uo_player WHERE player_id=800");
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertSame(1, $count);
    }

    public function testDBQueryToArrayWithCachingEnabledReturnsArray(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $result = DBQueryToArray("SELECT player_id FROM uo_player WHERE player_id=800");
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertCount(1, $result);
    }

    public function testDBQueryToRowWithCachingEnabledReturnsRow(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $row = DBQueryToRow("SELECT firstname FROM uo_player WHERE player_id=800");
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertSame('Ari', $row['firstname']);
    }

    public function testDBQueryCacheableReturnsFalseForNonGetMethod(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $result = DBQueryCacheable('SELECT 1');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertFalse($result);
    }

    public function testDBQueryCacheableReturnsFalseForNonSelectQuery(): void
    {
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'true';
        $result = DBQueryCacheable('UPDATE foo SET bar=1');
        $serverConf['PersistentCacheEnabled'] = 'false';
        $this->assertFalse($result);
    }

    public function testDBSetRowUpdatesIntegerField(): void
    {
        $original = (int) DBQueryToValue("SELECT fields FROM uo_location WHERE id=400");
        DBSetRow('uo_location', ['fields' => 3], 'id=400');
        $updated = (int) DBQueryToValue("SELECT fields FROM uo_location WHERE id=400");
        $this->assertSame(3, $updated);
        DBSetRow('uo_location', ['fields' => $original], 'id=400');
    }

    // --- DBEscapeString edge case ---

    public function testDBEscapeStringHandlesInvalidUtf8(): void
    {
        // "\xc0\x80" is an overlong encoding — invalid UTF-8. mb_check_encoding fails
        // so DBEscapeString calls convertToUtf8() before escaping.
        // convertToUtf8() falls back to treating the input as ISO-8859-1: 0xC0 -> U+00C0 (À),
        // 0x80 -> U+0080 (control), re-encoded as UTF-8 bytes C3 80 C2 80. Neither byte needs
        // escaping, so DBEscapeString leaves the converted string unchanged.
        $result = DBEscapeString("\xc0\x80");
        $this->assertSame("\xc3\x80\xc2\x80", $result);
    }

    // --- GetServerName ---

    public function testGetServerNameReturnsServerNameWhenSet(): void
    {
        // bootstrapEnvironment sets $_SERVER['SERVER_NAME'] = '127.0.0.1'
        $name = GetServerName();
        $this->assertIsString($name);
        $this->assertNotSame('', $name);
    }

    public function testGetServerNameFallsBackToHttpHost(): void
    {
        $original = $_SERVER['SERVER_NAME'] ?? null;
        unset($_SERVER['SERVER_NAME']);
        $originalHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'fallback.host';
        try {
            $name = GetServerName();
            $this->assertSame('fallback.host', $name);
        } finally {
            if ($original !== null) {
                $_SERVER['SERVER_NAME'] = $original;
            }
            if ($originalHost !== null) {
                $_SERVER['HTTP_HOST'] = $originalHost;
            } else {
                unset($_SERVER['HTTP_HOST']);
            }
        }
    }

    // --- DBAbort ---

    public function testDBAbortThrowsWhenExceptionModeEnabled(): void
    {
        DBSetExceptionMode(true);
        try {
            $this->expectException(DBOperationException::class);
            DBAbort('test context', 'SELECT 1', 'test error');
        } finally {
            DBSetExceptionMode(false);
        }
    }

    // --- CheckDB ---

    public function testCheckDBRunsWithoutErrorOnUpToDateSchema(): void
    {
        // The fixture DB schema is current; CheckDB scans for missing upgrades and finds none.
        CheckDB();
        $this->assertTrue(true);
    }

    // --- DBFieldType type returns ---

    public function testDBFieldTypeReturnsTinyintForTinyintColumn(): void
    {
        $result = DBQuery("SELECT revoked FROM uo_api_token LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('tinyint', $type);
    }

    public function testDBFieldTypeReturnsDatetimeForDatetimeColumn(): void
    {
        $result = DBQuery("SELECT time FROM uo_game LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('datetime', $type);
    }

    public function testDBFieldTypeReturnsTimestampForTimestampColumn(): void
    {
        $result = DBQuery("SELECT created_at FROM uo_api_token LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('timestamp', $type);
    }

    public function testDBFieldTypeReturnsStringForVarcharColumn(): void
    {
        $result = DBQuery("SELECT label FROM uo_api_token LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('string', $type);
    }

    public function testDBFieldTypeReturnsBlobForMediumblobColumn(): void
    {
        $result = DBQuery("SELECT thumb FROM uo_image LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('blob', $type);
    }

    // --- DBCastArray default case (string/non-int/non-real columns) ---

    public function testDBCastArrayDefaultCasePreservesStringValue(): void
    {
        $result = DBQuery("SELECT firstname FROM uo_player WHERE player_id=800");
        $row = mysqli_fetch_assoc($result);
        $casted = DBCastArray($result, $row);
        $this->assertSame('Ari', $casted['firstname']);
    }

    // --- DBSetRow with null value for int column ---

    public function testDBSetRowSetsIntColumnToNull(): void
    {
        // halftime int(10) DEFAULT NULL — nullable, so NULL is a valid value
        $original = DBQueryToValue("SELECT halftime FROM uo_game WHERE game_id=700");
        try {
            DBSetRow('uo_game', ['halftime' => null], 'game_id=700');
            self::flushQueryCaches();
            $updated = DBQueryToValue("SELECT halftime FROM uo_game WHERE game_id=700");
            $this->assertNull($updated);
        } finally {
            DBSetRow('uo_game', ['halftime' => $original], 'game_id=700');
            self::flushQueryCaches();
        }
    }

    // --- DBFieldType — additional type returns ---

    public function testDBFieldTypeReturnsUnknownForInvalidOffset(): void
    {
        // mysqli_fetch_field_direct throws ValueError for out-of-bounds offset in
        // PHP 8.3, so the guard at database.php:907 is only reachable by passing
        // a non-result argument. Use a temporary field-info mock instead.
        // This path cannot be reached in PHP 8.3+ — skip to avoid ValueError.
        $this->markTestSkipped('mysqli_fetch_field_direct throws ValueError in PHP 8.3+ before the guard executes');
    }

    public function testDBFieldTypeReturnsNullForNullExpression(): void
    {
        $result = DBQuery("SELECT NULL AS n");
        $this->assertSame('null', DBFieldType($result, 0));
    }

    public function testDBFieldTypeReturnsDateForCastDate(): void
    {
        $result = DBQuery("SELECT CAST('2026-01-01' AS DATE) AS d");
        $this->assertSame('date', DBFieldType($result, 0));
    }

    public function testDBFieldTypeReturnsTimeForCastTime(): void
    {
        $result = DBQuery("SELECT CAST('10:00:00' AS TIME) AS t");
        $this->assertSame('time', DBFieldType($result, 0));
    }

    public function testDBFieldTypeReturnsYearForYearColumn(): void
    {
        // MariaDB CAST(... AS YEAR) is not supported; use a temp table with YEAR column.
        DBQuery("CREATE TEMPORARY TABLE tmp_year_test (y YEAR NOT NULL DEFAULT '2026')");
        DBQuery("INSERT INTO tmp_year_test (y) VALUES (2026)");
        $result = DBQuery("SELECT y FROM tmp_year_test LIMIT 1");
        $type = DBFieldType($result, 0);
        DBQuery("DROP TEMPORARY TABLE IF EXISTS tmp_year_test");
        $this->assertSame('year', $type);
    }

    public function testDBFieldTypeReturnsCharForCharColumn(): void
    {
        // uo_api_token.token_hash is char(64) — MariaDB reports CHAR as MYSQLI_TYPE_STRING.
        // Note: MYSQLI_TYPE_CHAR === MYSQLI_TYPE_TINY (both = 1), so the 'char' branch at
        // database.php:939 is unreachable; CHAR columns arrive as MYSQLI_TYPE_STRING.
        $result = DBQuery("SELECT token_hash FROM uo_api_token LIMIT 1");
        $type = DBFieldType($result, 0);
        $this->assertSame('string', $type);
    }

    // --- Error paths (DBAbort called when query fails with exception mode) ---
    // PHP 8.1+ enables mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT) by
    // default, which causes mysqli_query() to throw mysqli_sql_exception directly
    // before returning false. To cover the `if (!$result)` guard branches, we
    // temporarily disable mysqli error reporting so that mysqli_query returns false
    // and then DBAbort is called. DBSetExceptionMode(true) makes DBAbort throw
    // DBOperationException instead of calling die().

    private function withMysqliSilentMode(callable $fn): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        DBSetExceptionMode(true);
        try {
            $fn();
        } finally {
            DBSetExceptionMode(false);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }
    }

    public function testDBQueryThrowsOnInvalidSql(): void
    {
        $this->withMysqliSilentMode(function () {
            $this->expectException(DBOperationException::class);
            DBQuery("INVALID SQL SYNTAX HERE");
        });
    }

    public function testDBQueryToValueThrowsOnInvalidSql(): void
    {
        $this->withMysqliSilentMode(function () {
            $this->expectException(DBOperationException::class);
            DBQueryToValue("INVALID SQL");
        });
    }

    public function testDBQueryRowCountThrowsOnInvalidSql(): void
    {
        $this->withMysqliSilentMode(function () {
            $this->expectException(DBOperationException::class);
            DBQueryRowCount("INVALID SQL");
        });
    }

    public function testDBQueryToArrayThrowsOnInvalidSql(): void
    {
        $this->withMysqliSilentMode(function () {
            $this->expectException(DBOperationException::class);
            DBQueryToArray("INVALID SQL");
        });
    }

    public function testDBQueryToRowThrowsOnInvalidSql(): void
    {
        $this->withMysqliSilentMode(function () {
            $this->expectException(DBOperationException::class);
            DBQueryToRow("INVALID SQL");
        });
    }

    public function testGetDBVersionReturnsZeroWhenTableEmpty(): void
    {
        $rows = DBQueryToArray("SELECT version, updated FROM uo_database ORDER BY version");
        DBQuery("DELETE FROM uo_database");
        self::flushQueryCaches();
        try {
            $version = getDBVersion();
            $this->assertSame(0, $version);
        } finally {
            foreach ($rows as $row) {
                DBQuery(sprintf(
                    "INSERT IGNORE INTO uo_database (version, updated) VALUES (%d, '%s')",
                    (int) $row['version'],
                    DBEscapeString($row['updated'])
                ));
            }
            self::flushQueryCaches();
        }
    }
}
