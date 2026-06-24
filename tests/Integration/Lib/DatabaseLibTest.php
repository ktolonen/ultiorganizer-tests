<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class DatabaseLibTest extends TestCase
{
    // Fixture: location 400 ('Harness Field Complex'), player 800 ('Ari Ace', team=300).
    // Tests exercise DB utility functions in lib/database.php directly.

    private ?int $insertedUrlId = null;

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
        $this->assertIsString(DBUserErrorMessage());
    }

    public function testDBErrorReturnsEmptyStringWhenNoError(): void
    {
        DBQuery('SELECT 1');
        $this->assertSame('', DBError());
    }

    // --- DBShouldThrowExceptions / DBSetExceptionMode ---

    public function testDBShouldThrowExceptionsReturnsBool(): void
    {
        $this->assertIsBool(DBShouldThrowExceptions());
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
        $this->assertIsString(DBStat());
    }

    public function testDBClientInfoReturnsString(): void
    {
        $this->assertIsString(DBClientInfo());
    }

    public function testDBHostInfoReturnsString(): void
    {
        $this->assertIsString(DBHostInfo());
    }

    public function testDBServerInfoReturnsString(): void
    {
        $this->assertIsString(DBServerInfo());
    }

    // --- DBProtocolInfo ---

    public function testDBProtocolInfoReturnsInteger(): void
    {
        $this->assertIsInt(DBProtocolInfo());
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
        $this->assertIsFloat($casted['lat']);
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
        $result = DBEscapeString("\xc0\x80");
        $this->assertIsString($result);
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
}
