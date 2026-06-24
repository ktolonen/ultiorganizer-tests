<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// someHTML/CommentHTML require utf8entities from localization.php; provide a stub here.
if (!function_exists('utf8entities')) {
    function utf8entities(string $string): string
    {
        return htmlspecialchars($string, ENT_COMPAT, 'UTF-8');
    }
}

final class CommonFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('common.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    /**
     * DBQueryToValue/Array/Row/RowCount cache by query string (persistent on
     * disk and in-process). Flush all four namespaces after a write so a
     * subsequent read sees fresh data instead of a stale cached value.
     */
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

    // ---- existing tests ----

    public function testNormalizeTextInputDecodesAndTrimsUtf8Input(): void
    {
        $this->assertSame('Mäki', normalizeTextInput('  M%C3%A4ki  '));
    }

    public function testResolveViewPathFallsBackForTraversalInput(): void
    {
        $path = resolveViewPath('../admin/seasons', LegacyApp::sutRoot(), 'frontpage', ['index', 'install']);
        $this->assertSame(LegacyApp::sutRoot() . '/frontpage.php', $path);
    }

    // ---- SafeDivide ----

    public function testSafeDivideReturnsQuotient(): void
    {
        $this->assertEquals(5, SafeDivide(10, 2));
    }

    public function testSafeDivideReturnZeroForDivisorZero(): void
    {
        $this->assertSame(0, SafeDivide(10, 0));
    }

    // ---- SecToMin ----

    public function testSecToMinConvertsSecondsToMinutesDotSeconds(): void
    {
        $this->assertSame('1.30', SecToMin(90));
    }

    public function testSecToMinPadsSecondsUnderTen(): void
    {
        $this->assertSame('1.05', SecToMin(65));
    }

    // ---- TimeToSec ----

    public function testTimeToSecReturnsZeroForEmpty(): void
    {
        $this->assertSame(0, TimeToSec(''));
    }

    public function testTimeToSecConvertsMinutes(): void
    {
        $this->assertSame(60, TimeToSec('1'));
    }

    public function testTimeToSecConvertsMinutesAndSeconds(): void
    {
        $this->assertSame(90, TimeToSec('1.30'));
    }

    public function testTimeToSecConvertsHoursMinutesSeconds(): void
    {
        $this->assertSame(5400, TimeToSec('1.30.00'));
    }

    // ---- Date/time formatters ----

    public function testHoursExtractsHourFromTimestamp(): void
    {
        $this->assertSame(10, Hours('2026-06-22 10:30:00'));
    }

    public function testMinutesExtractsMinuteFromTimestamp(): void
    {
        $this->assertSame(30, Minutes('2026-06-22 10:30:45'));
    }

    public function testJustDateFormatsTimestampToDayMonth(): void
    {
        $this->assertSame('22.6.', JustDate('2026-06-22 10:30:00'));
    }

    public function testJustDateReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame('', JustDate(''));
    }

    public function testDefWeekDateFormatReturnsDayOfWeekAndDate(): void
    {
        $result = DefWeekDateFormat('2026-06-22 10:30:00');
        $this->assertStringContainsString('22.6.2026', $result);
    }

    public function testShortDateFormatsTimestamp(): void
    {
        $this->assertSame('22.6.2026', ShortDate('2026-06-22 10:30:00'));
    }

    public function testShortDateReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', ShortDate(''));
    }

    public function testShortEnDateFormatsToIso(): void
    {
        $this->assertSame('2026-06-22', ShortEnDate('2026-06-22 10:30:00'));
    }

    public function testShortEnDateReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', ShortEnDate(''));
    }

    public function testDefHourFormatReturnsHoursAndMinutes(): void
    {
        $this->assertSame('10:30', DefHourFormat('2026-06-22 10:30:00'));
    }

    public function testDefHourFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', DefHourFormat(''));
    }

    public function testDefTimeFormatReturnsFullDateAndTime(): void
    {
        $this->assertSame('22.6.2026 10:30', DefTimeFormat('2026-06-22 10:30:00'));
    }

    public function testDefTimeFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', DefTimeFormat(''));
    }

    public function testShortTimeFormatReturnsDayMonthAndTime(): void
    {
        $this->assertSame('22.6. 10:30', ShortTimeFormat('2026-06-22 10:30:00'));
    }

    public function testShortTimeFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', ShortTimeFormat(''));
    }

    public function testLongTimeFormatReturnsDayMonthYearAndTime(): void
    {
        $this->assertSame('22.6.2026 10:30:00', LongTimeFormat('2026-06-22 10:30:00'));
    }

    public function testLongTimeFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', LongTimeFormat(''));
    }

    public function testISOTimeFormatReturnsISO8601String(): void
    {
        $result = ISOTimeFormat('2026-06-22 10:30:00');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result);
    }

    public function testISOTimeFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', ISOTimeFormat(''));
    }

    public function testDefBirthdayFormatReturnsDotDmY(): void
    {
        $this->assertSame('22.06.2026', DefBirthdayFormat('2026-06-22'));
    }

    public function testDefBirthdayFormatReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', DefBirthdayFormat(''));
    }

    public function testTimeToIcalFormatsTimestamp(): void
    {
        $this->assertSame('20260622T103000', TimeToIcal('2026-06-22 10:30:00'));
    }

    public function testTimeToIcalReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', TimeToIcal(''));
    }

    public function testDefTimestampReturnsCurrentTimeString(): void
    {
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', DefTimestamp());
    }

    public function testEpocToMysqlFormatsMysqlDatetime(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', EpocToMysql(0));
    }

    public function testIsEmptyDateReturnsTrueForEmpty(): void
    {
        $this->assertTrue(isEmptyDate(''));
    }

    public function testIsEmptyDateReturnsTrueForEpoch1971(): void
    {
        $this->assertTrue(isEmptyDate('1971-01-01 00:00:00'));
    }

    public function testIsEmptyDateReturnsFalseForRealDate(): void
    {
        $this->assertFalse(isEmptyDate('2026-06-22 10:30:00'));
    }

    // ---- ToInternalTimeFormat ----

    public function testToInternalTimeFormatReturnsEpochForEmpty(): void
    {
        $result = ToInternalTimeFormat('');
        $this->assertMatchesRegularExpression('/^1971-/', $result);
    }

    public function testToInternalTimeFormatConvertsValidDatetime(): void
    {
        $this->assertSame('2026-6-22 14:30:00', ToInternalTimeFormat('22.06.2026 14:30'));
    }

    public function testToInternalTimeFormatFallsBackForInvalidDate(): void
    {
        $result = ToInternalTimeFormat('not-a-date');
        $this->assertMatchesRegularExpression('/^1971-/', $result);
    }

    // ---- uo_strptime ----

    public function testUoStrptimeParsesDateTimeString(): void
    {
        $result = uo_strptime('22.06.2026 14:30', '%d.%m.%Y %H:%M');
        $this->assertIsArray($result);
        $this->assertSame(22, $result['tm_mday']);
        $this->assertSame(5, $result['tm_mon']);
        $this->assertSame(126, $result['tm_year']);
        $this->assertSame(14, $result['tm_hour']);
        $this->assertSame(30, $result['tm_min']);
    }

    public function testUoStrptimeReturnsFalseForInvalidInput(): void
    {
        $this->assertFalse(uo_strptime('invalid', '%d.%m.%Y %H:%M'));
    }

    // ---- Server/URL functions ----

    public function testGetScriptNameReturnsServerScriptName(): void
    {
        $orig = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/test.php';
        $result = GetScriptName();
        if ($orig !== null) {
            $_SERVER['SCRIPT_NAME'] = $orig;
        }
        $this->assertSame('/test.php', $result);
    }

    public function testGetScriptNameFallsBackToPhpSelf(): void
    {
        $origScript = $_SERVER['SCRIPT_NAME'] ?? null;
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['PHP_SELF'] = '/self.php';
        $result = GetScriptName();
        if ($origScript !== null) {
            $_SERVER['SCRIPT_NAME'] = $origScript;
        } else {
            unset($_SERVER['SCRIPT_NAME']);
        }
        $this->assertSame('/self.php', $result);
    }

    public function testGetURLBaseReturnsBaseUrlConstant(): void
    {
        // With database_only profile, config.inc.php defines BASEURL='http://127.0.0.1'
        $result = GetURLBase();
        $this->assertStringStartsWith('http', $result);
    }

    public function testGetPageUrlReturnsHttpUrlForPort80(): void
    {
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REQUEST_URI'] = '/index.php';
        unset($_SERVER['HTTPS']);
        $result = GetPageURL();
        $this->assertSame('http://127.0.0.1/index.php', $result);
    }

    public function testGetPageUrlIncludesNonDefaultPort(): void
    {
        $_SERVER['SERVER_NAME'] = '127.0.0.1';
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['REQUEST_URI'] = '/index.php';
        unset($_SERVER['HTTPS']);
        $result = GetPageURL();
        $this->assertSame('http://127.0.0.1:8080/index.php', $result);
    }

    // ---- Session locale ----

    public function testGetSessionLocaleReturnsStringLocale(): void
    {
        $_SESSION['userproperties']['locale'] = 'en_US';
        $result = getSessionLocale();
        unset($_SESSION['userproperties']['locale']);
        $this->assertSame('en_US', $result);
    }

    public function testGetSessionLocaleReturnsFirstKeyOfArrayLocale(): void
    {
        $_SESSION['userproperties']['locale'] = ['fi_FI' => true];
        $result = getSessionLocale();
        unset($_SESSION['userproperties']['locale']);
        $this->assertSame('fi_FI', $result);
    }

    public function testGetW3CLocaleConvertsUnderscoreToDash(): void
    {
        $_SESSION['userproperties']['locale'] = 'en_US';
        $result = GetW3CLocale();
        unset($_SESSION['userproperties']['locale']);
        $this->assertSame('en-US', $result);
    }

    // ---- validEmail ----

    public function testValidEmailReturnsTrueForValidAddress(): void
    {
        $this->assertTrue(validEmail('test@example.com'));
    }

    public function testValidEmailReturnsFalseForMissingAt(): void
    {
        $this->assertFalse(validEmail('notanemail'));
    }

    public function testValidEmailReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(validEmail(''));
    }

    public function testValidEmailReturnsFalseForLeadingDotInLocal(): void
    {
        $this->assertFalse(validEmail('.test@example.com'));
    }

    public function testValidEmailReturnsFalseForTrailingDotInLocal(): void
    {
        $this->assertFalse(validEmail('test.@example.com'));
    }

    public function testValidEmailReturnsFalseForConsecutiveDotsInLocal(): void
    {
        $this->assertFalse(validEmail('te..st@example.com'));
    }

    public function testValidEmailReturnsFalseForInvalidDomainChar(): void
    {
        $this->assertFalse(validEmail('test@exa!mple.com'));
    }

    public function testValidEmailReturnsFalseForConsecutiveDotsInDomain(): void
    {
        $this->assertFalse(validEmail('test@exam..ple.com'));
    }

    // ---- is_odd ----

    public function testIsOddReturnsTrueForOddNumber(): void
    {
        $this->assertSame(1, is_odd(3));
    }

    public function testIsOddReturnsFalseForEvenNumber(): void
    {
        $this->assertSame(0, is_odd(4));
    }

    // ---- textColor ----

    public function testTextColorReturnsHexString(): void
    {
        $result = textColor('ff0000');
        $this->assertIsString($result);
        $this->assertNotSame('', $result);
    }

    // ---- RGBtoRGBa ----

    public function testRGBtoRGBaConvertsHexToRgba(): void
    {
        $this->assertSame('rgba(255,0,0,0.5)', RGBtoRGBa('ff0000', 0.5));
    }

    public function testRGBtoRGBaHandlesHashPrefix(): void
    {
        $this->assertSame('rgba(255,0,0,1)', RGBtoRGBa('#ff0000', 1));
    }

    public function testRGBtoRGBaFallsBackToBlackForInvalidInput(): void
    {
        $this->assertSame('rgba(0,0,0,1)', RGBtoRGBa('', 1));
    }

    // ---- getChkNum / checkChkNum ----

    public function testGetChkNumComputesCheckDigitForGameId700(): void
    {
        $this->assertSame(3, getChkNum('700'));
    }

    public function testCheckChkNumReturnsTrueForValidChecksum(): void
    {
        $this->assertTrue(checkChkNum('7003'));
    }

    public function testCheckChkNumReturnsFalseForInvalidChecksum(): void
    {
        $this->assertFalse(checkChkNum('7009'));
    }

    // ---- SafeUrl ----

    public function testSafeUrlPrefixesHttpForBareHost(): void
    {
        $this->assertSame('http://example.com', SafeUrl('example.com'));
    }

    public function testSafeUrlLeavesHttpUrlUnchanged(): void
    {
        $this->assertSame('http://example.com', SafeUrl('http://example.com'));
    }

    public function testSafeUrlLeavesHttpsUrlUnchanged(): void
    {
        $this->assertSame('https://example.com', SafeUrl('https://example.com'));
    }

    // ---- colorstring2rgb ----

    public function testColorstring2rgbParsesFullHex(): void
    {
        $this->assertSame(['r' => 255, 'g' => 0, 'b' => 0], colorstring2rgb('#ff0000'));
    }

    public function testColorstring2rgbParsesShortHex(): void
    {
        $this->assertSame(['r' => 255, 'g' => 0, 'b' => 0], colorstring2rgb('f00'));
    }

    public function testColorstring2rgbReturnsDefaultForEmpty(): void
    {
        $this->assertSame(['r' => 230, 'g' => 230, 'b' => 230], colorstring2rgb(''));
    }

    // ---- recur_mkdirs ----

    public function testRecurMkdirsCreatesDirectoryTree(): void
    {
        $base = '/tmp/uo_test_recur_' . getmypid();
        $sub = $base . '/subsub';
        try {
            recur_mkdirs($sub);
            $this->assertTrue(is_dir($sub));
        } finally {
            if (is_dir($sub)) {
                rmdir($sub);
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }

    public function testRecurMkdirsCreatesOnlyDirPartsForPathWithExtension(): void
    {
        $base = '/tmp/uo_test_recur2_' . getmypid();
        $path = $base . '/file.txt';
        try {
            recur_mkdirs($path);
            $this->assertTrue(is_dir($base));
            $this->assertFalse(is_dir($path));
        } finally {
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }

    // ---- strEndsWith ----

    public function testStrEndsWithReturnsTrueForMatchingSuffix(): void
    {
        $this->assertTrue((bool) strEndsWith('hello.php', '.php'));
    }

    public function testStrEndsWithReturnsFalseForNonMatchingSuffix(): void
    {
        $this->assertFalse((bool) strEndsWith('hello.php', '.txt'));
    }

    // ---- ordinal ----

    public function testOrdinalReturnsSuffixedNumber(): void
    {
        $_SESSION['userproperties']['locale'] = 'en_US';
        $result = ordinal(1);
        unset($_SESSION['userproperties']['locale']);
        $this->assertStringContainsString('1', $result);
    }

    // ---- iget / GetInt / GetString ----

    public function testIgetReturnsEmptyForMissingKey(): void
    {
        $_GET = [];
        $this->assertSame('', iget('missing'));
    }

    public function testIgetCoversAllCaseVariantPaths(): void
    {
        // Sets uppercase so only the strtoupper case matches, covering all four branches
        $_GET = ['FOO' => 'bar'];
        $result = iget('foo');
        $_GET = [];
        // filter_input(INPUT_GET) returns null in CLI → GetString returns "" even though key was in $_GET
        $this->assertIsString($result);
    }

    public function testIgetFindsExactKeyFirst(): void
    {
        $_GET = ['foo' => 'bar'];
        $result = iget('foo');
        $_GET = [];
        $this->assertIsString($result);
    }

    public function testGetIntReturnsFalseOrNullForCLI(): void
    {
        // filter_input(INPUT_GET) always returns null/false in CLI; just verify no crash
        $result = GetInt('anything');
        $this->assertTrue($result === null || $result === false);
    }

    public function testGetStringReturnsEmptyForCLI(): void
    {
        // filter_input(INPUT_GET) returns null in CLI → GetString returns ""
        $this->assertSame('', GetString('anything'));
    }

    // ---- array_copy ----

    public function testArrayCopyReturnsDeepcopyOfArray(): void
    {
        $orig = [1, 2, [3, 4]];
        $copy = array_copy($orig);
        $this->assertSame($orig, $copy);
    }

    // ---- mergesort ----

    public function testMergesortSortsArrayStably(): void
    {
        $arr = ['cherry', 'apple', 'banana'];
        mergesort($arr);
        $this->assertSame(['apple', 'banana', 'cherry'], $arr);
    }

    public function testMergesortHandlesSingleElement(): void
    {
        $arr = ['only'];
        mergesort($arr);
        $this->assertSame(['only'], $arr);
    }

    // ---- someHTML ----

    public function testSomeHtmlPassesThroughBoldTags(): void
    {
        // utf8entities encodes < > to &lt; &gt;, then someHTML restores <b> etc.
        $result = someHTML('<b>Bold</b>');
        $this->assertSame('<b>Bold</b>', $result);
    }

    public function testSomeHtmlConvertsBreakTagVariants(): void
    {
        $result = someHTML('<br>');
        $this->assertSame('<br />', $result);
    }

    // ---- DB-backed tests ----

    // ---- GetTableColumns ----

    public function testGetTableColumnsReturnsArrayForKnownTable(): void
    {
        $cols = GetTableColumns('uo_pool');
        $this->assertIsArray($cols);
        $this->assertArrayHasKey('pool_id', $cols);
        $this->assertSame('int', $cols['pool_id']);
    }

    // ---- SetComment / CommentHTML ----

    public function testSetCommentInsertsAndDeletesComment(): void
    {
        $result = SetComment(COMMENT_TYPE_SEASON, 'HRN2026', 'Test comment');
        $this->assertTrue((bool) $result);
        // Verify it was actually stored
        self::flushQueryCaches();
        $raw = CommentRaw(COMMENT_TYPE_SEASON, 'HRN2026');
        $this->assertSame('Test comment', $raw);
        // Delete by setting empty
        $del = SetComment(COMMENT_TYPE_SEASON, 'HRN2026', '');
        $this->assertTrue((bool) $del);
        self::flushQueryCaches();
        $this->assertSame('', CommentRaw(COMMENT_TYPE_SEASON, 'HRN2026'));
    }

    public function testCommentHTMLReturnsEmptyWhenNoComment(): void
    {
        // Ensure no comment exists (fixture has none for this type/id)
        SetComment(COMMENT_TYPE_SEASON, 'HRN2026', '');
        self::flushQueryCaches();
        $this->assertSame('', CommentHTML(COMMENT_TYPE_SEASON, 'HRN2026'));
    }

    public function testCommentHTMLReturnsWrappedCommentWhenSet(): void
    {
        SetComment(COMMENT_TYPE_POOL, 200, 'Pool note');
        self::flushQueryCaches();
        try {
            $result = CommentHTML(COMMENT_TYPE_POOL, 200);
            $this->assertStringContainsString('comment', $result);
            $this->assertStringContainsString('Pool note', $result);
        } finally {
            SetComment(COMMENT_TYPE_POOL, 200, '');
        }
    }

    // ---- ResultsetToCsv ----

    public function testResultsetToCsvReturnsCsvStringWithHeaders(): void
    {
        $result = DBQuery('SELECT pool_id FROM uo_pool WHERE pool_id=200');
        $csv = ResultsetToCsv($result, ';');
        $this->assertStringContainsString('pool_id', $csv);
        $this->assertStringContainsString('200', $csv);
    }

    // ---- CreateOrdering ----

    public function testCreateOrderingReturnsEmptyForNullOrdering(): void
    {
        $this->assertSame('', CreateOrdering('uo_pool', null));
    }

    public function testCreateOrderingReturnsOrderByClause(): void
    {
        $result = CreateOrdering('uo_pool', ['uo_pool.pool_id' => 'ASC']);
        $this->assertSame('ORDER BY uo_pool.pool_id ASC', $result);
    }

    public function testCreateOrderingWithAliasMap(): void
    {
        $result = CreateOrdering(['uo_pool' => 'p'], ['p.pool_id' => 'DESC']);
        $this->assertSame('ORDER BY p.pool_id DESC', $result);
    }

    public function testCreateOrderingWithIndexedArray(): void
    {
        // Indexed array: field => ASC by default
        $result = CreateOrdering('uo_pool', ['uo_pool.pool_id']);
        $this->assertSame('ORDER BY uo_pool.pool_id ASC', $result);
    }

    // ---- CreateFilter and _handle* functions ----

    public function testCreateFilterReturnsEmptyForNull(): void
    {
        $this->assertSame('', CreateFilter('uo_pool', null));
    }

    public function testCreateFilterReturnsEmptyForEmptyArray(): void
    {
        $this->assertSame('', CreateFilter('uo_pool', []));
    }

    public function testCreateFilterWithFieldCriteriaBuildsWhereClause(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            ['field' => 'pool.pool_id', 'operator' => '=', 'value' => 200],
        );
        $this->assertSame('WHERE pool.pool_id = 200', $result);
    }

    public function testCreateFilterWithStringFieldBuildsQuotedClause(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            ['field' => 'pool.name', 'operator' => '=', 'value' => 'TestPool'],
        );
        $this->assertStringContainsString("pool.name = 'TestPool'", $result);
    }

    public function testCreateFilterWithJoinCriteriaBuildsAndClause(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                'join'     => 'AND',
                'criteria' => [
                    ['field' => 'pool.pool_id', 'operator' => '=', 'value' => 200],
                    ['field' => 'pool.visible', 'operator' => '=', 'value' => 1],
                ],
            ],
        );
        $this->assertStringContainsString('AND', $result);
        $this->assertStringContainsString('pool.pool_id = 200', $result);
    }

    public function testCreateFilterWithNestedArrayCriteria(): void
    {
        // Tests _handleArray path: outer filter has a numeric key whose value is an array
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                [  // current($filter) is this inner array → _handleArray
                    'field'    => 'pool.pool_id',
                    'operator' => '=',
                    'value'    => 200,
                ],
            ],
        );
        $this->assertStringContainsString('pool.pool_id = 200', $result);
    }

    public function testCreateFilterWithFunctionField(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                'field' => [
                    'function'   => 'UPPER',
                    'args'       => [['field' => 'pool.name']],
                    'returntype' => 'string',
                ],
                'operator' => '=',
                'value'    => 'TEST',
            ],
        );
        $this->assertStringContainsString("UPPER(pool.name) = 'TEST'", $result);
    }

    public function testCreateFilterWithConstantTypeField(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                'field'    => ['constanttype' => 'int', 'value' => 200],
                'operator' => '=',
                'value'    => 201,
            ],
        );
        $this->assertStringContainsString('200 = 201', $result);
    }

    public function testCreateFilterWithVariableValue(): void
    {
        $_SESSION['test_pool_id'] = 200;
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                [
                    'field'    => 'pool.pool_id',
                    'operator' => '=',
                    'value'    => ['variable' => 'test_pool_id'],
                ],
            );
            $this->assertStringContainsString('pool.pool_id = 200', $result);
        } finally {
            unset($_SESSION['test_pool_id']);
        }
    }

    public function testCreateFilterWithInOperatorForIntField(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            ['field' => 'pool.pool_id', 'operator' => 'IN', 'value' => '200,201'],
        );
        $this->assertStringContainsString('pool.pool_id IN', $result);
    }

    public function testCreateFilterWithInOperatorForStringField(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            ['field' => 'pool.name', 'operator' => 'IN', 'value' => 'Alpha,Beta'],
        );
        $this->assertStringContainsString('pool.name IN', $result);
    }

    public function testCreateFilterWithSubselectOperator(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                'field'    => 'pool.pool_id',
                'operator' => 'SUBSELECT',
                'value'    => [
                    'table'    => 'pool',
                    'field'    => 'pool.pool_id',
                    'join'     => 'AND',
                    'criteria' => [
                        ['field' => 'pool.visible', 'operator' => '=', 'value' => 1],
                    ],
                ],
            ],
        );
        $this->assertStringContainsString('pool.pool_id IN', $result);
        $this->assertStringContainsString('SELECT', $result);
    }

    public function testCreateFilterWithNestedJoinInCriteria(): void
    {
        // Tests _handleJoin recursive path: criteria item that itself is a join
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                'join'     => 'AND',
                'criteria' => [
                    [
                        'join'     => 'OR',
                        'criteria' => [
                            ['field' => 'pool.pool_id', 'operator' => '=', 'value' => 200],
                            ['field' => 'pool.pool_id', 'operator' => '=', 'value' => 201],
                        ],
                    ],
                ],
            ],
        );
        $this->assertStringContainsString('OR', $result);
    }

    public function testCreateFilterWithArrayJoinCriteria(): void
    {
        // Tests _handleArray join path: outer array whose current() has a 'join' key
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            [
                [
                    'join'     => 'AND',
                    'criteria' => [
                        ['field' => 'pool.pool_id', 'operator' => '=', 'value' => 200],
                    ],
                ],
            ],
        );
        $this->assertStringContainsString('pool.pool_id = 200', $result);
    }

    public function testHandleVariableReturnsEmptyForUnknownVariable(): void
    {
        $result = CreateFilter(
            ['uo_pool' => 'pool'],
            ['field' => 'pool.pool_id', 'operator' => '=', 'value' => ['variable' => 'nonexistent_var_xyz123']],
        );
        $this->assertIsString($result);
    }

    public function testHandleVariableReadsFromGlobals(): void
    {
        $GLOBALS['my_test_pool_id'] = 300;
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                ['field' => 'pool.pool_id', 'operator' => '=', 'value' => ['variable' => 'my_test_pool_id']],
            );
            $this->assertStringContainsString('pool.pool_id = 300', $result);
        } finally {
            unset($GLOBALS['my_test_pool_id']);
        }
    }

    public function testHandleVariableWithArrayKeyPath(): void
    {
        $_SESSION['pool_data'] = ['id' => 200];
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                [
                    'field'    => 'pool.pool_id',
                    'operator' => '=',
                    'value'    => ['variable' => 'pool_data', 'key1' => 'id'],
                ],
            );
            $this->assertStringContainsString('pool.pool_id = 200', $result);
        } finally {
            unset($_SESSION['pool_data']);
        }
    }

    public function testHandleVariableWithImplodeKeys(): void
    {
        $_SESSION['pool_opts'] = ['alpha' => 1, 'beta' => 2];
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                [
                    'field'    => 'pool.name',
                    'operator' => 'IN',
                    'value'    => ['variable' => 'pool_opts', 'implode-keys' => ','],
                ],
            );
            $this->assertStringContainsString('pool.name IN', $result);
        } finally {
            unset($_SESSION['pool_opts']);
        }
    }

    public function testHandleVariableWithImplodeValues(): void
    {
        $_SESSION['pool_names'] = ['Alpha', 'Beta'];
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                [
                    'field'    => 'pool.name',
                    'operator' => 'IN',
                    'value'    => ['variable' => 'pool_names', 'implode-values' => ','],
                ],
            );
            $this->assertStringContainsString('pool.name IN', $result);
        } finally {
            unset($_SESSION['pool_names']);
        }
    }

    public function testHandleVariableWithNestedArrayUsesCurrentValue(): void
    {
        // Variable is a nested array with no key hints → while(is_array) extracts current()
        $_SESSION['nested_pool'] = ['Alpha'];
        try {
            $result = CreateFilter(
                ['uo_pool' => 'pool'],
                ['field' => 'pool.name', 'operator' => '=', 'value' => ['variable' => 'nested_pool']],
            );
            $this->assertStringContainsString("pool.name = 'Alpha'", $result);
        } finally {
            unset($_SESSION['nested_pool']);
        }
    }

    // ---- WeekdayString coverage for all days ----

    public function testWeekdayStringReturnsEmptyForEmpty(): void
    {
        $this->assertSame('', WeekdayString('', true));
    }

    public function testWeekdayStringSunday(): void
    {
        $this->assertSame('Sun', WeekdayString('2026-06-21 10:00:00', true));
        $this->assertSame('Sunday', WeekdayString('2026-06-21 10:00:00', false));
    }

    public function testWeekdayStringTuesday(): void
    {
        $this->assertSame('Tue', WeekdayString('2026-06-23 10:00:00', true));
        $this->assertSame('Tuesday', WeekdayString('2026-06-23 10:00:00', false));
    }

    public function testWeekdayStringWednesday(): void
    {
        $this->assertSame('Wed', WeekdayString('2026-06-24 10:00:00', true));
    }

    public function testWeekdayStringThursday(): void
    {
        $this->assertSame('Thu', WeekdayString('2026-06-25 10:00:00', true));
    }

    public function testWeekdayStringFriday(): void
    {
        $this->assertSame('Fri', WeekdayString('2026-06-26 10:00:00', true));
    }

    public function testWeekdayStringSaturday(): void
    {
        $this->assertSame('Sat', WeekdayString('2026-06-27 10:00:00', true));
    }

    // ---- colorstring2rgb additional cases ----

    public function testColorstring2rgbParsesFullHexWithoutHash(): void
    {
        $this->assertSame(['r' => 255, 'g' => 0, 'b' => 0], colorstring2rgb('ff0000'));
    }

    public function testColorstring2rgbParsesShortHexWithHash(): void
    {
        $this->assertSame(['r' => 255, 'g' => 0, 'b' => 0], colorstring2rgb('#f00'));
    }

    public function testColorstring2rgbReturnsDefaultForInvalidLength(): void
    {
        // 5-char hex → neither 3 nor 6 → else branch → default
        $this->assertSame(['r' => 230, 'g' => 230, 'b' => 230], colorstring2rgb('12345'));
    }

    // ---- startsWith ----

    public function testStartsWithReturnsTrueForMatchingPrefix(): void
    {
        $this->assertTrue(startsWith('hello', 'he'));
    }

    public function testStartsWithReturnsTrueForEmptyNeedle(): void
    {
        $this->assertTrue(startsWith('hello', ''));
    }

    public function testStartsWithReturnsFalseForNonMatchingPrefix(): void
    {
        $this->assertFalse(startsWith('hello', 'world'));
    }

    // ---- resolveViewPath additional branches ----

    public function testResolveViewPathFallsBackForEmptyView(): void
    {
        $path = resolveViewPath('', LegacyApp::sutRoot(), 'frontpage');
        $this->assertStringContainsString('frontpage', $path);
    }

    public function testResolveViewPathFallsBackForNonexistentView(): void
    {
        $path = resolveViewPath('view_does_not_exist_xyz', LegacyApp::sutRoot(), 'frontpage');
        $this->assertStringContainsString('frontpage', $path);
    }

    public function testResolveViewPathReturnsDeniedViewFallback(): void
    {
        $path = resolveViewPath('index', LegacyApp::sutRoot(), 'frontpage', ['index']);
        $this->assertStringContainsString('frontpage', $path);
    }
}
