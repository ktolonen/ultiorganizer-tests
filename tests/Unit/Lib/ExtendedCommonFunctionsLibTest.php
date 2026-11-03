<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ExtendedCommonFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('common.functions.php', 'bootstrap_only');
    }

    // --- is_odd ---

    public function testIsOddReturnsTruthyForOddNumbers(): void
    {
        $this->assertTrue((bool) is_odd(1));
        $this->assertTrue((bool) is_odd(3));
        $this->assertTrue((bool) is_odd(-1));
    }

    public function testIsOddReturnsFalsyForEvenNumbers(): void
    {
        $this->assertFalse((bool) is_odd(0));
        $this->assertFalse((bool) is_odd(2));
        $this->assertFalse((bool) is_odd(100));
    }

    // --- startsWith ---

    public function testStartsWithReturnsTrueWhenHaystackBeginsWithNeedle(): void
    {
        $this->assertTrue(startsWith('foobar', 'foo'));
    }

    public function testStartsWithReturnsFalseWhenHaystackDoesNotBeginWithNeedle(): void
    {
        $this->assertFalse(startsWith('foobar', 'bar'));
    }

    public function testStartsWithReturnsTrueForEmptyNeedle(): void
    {
        $this->assertTrue(startsWith('foobar', ''));
    }

    // --- strEndsWith ---

    public function testStrEndsWithReturnsTrueWhenHaystackEndsWithSuffix(): void
    {
        $this->assertTrue(strEndsWith('foobar', 'bar'));
    }

    public function testStrEndsWithReturnsFalseWhenHaystackDoesNotEndWithSuffix(): void
    {
        $this->assertFalse(strEndsWith('foobar', 'foo'));
    }

    // --- RGBtoRGBa ---

    public function testRGBtoRGBaConvertsHexStringWithHash(): void
    {
        $this->assertSame('rgba(255,128,64,0.5)', RGBtoRGBa('#FF8040', 0.5));
    }

    public function testRGBtoRGBaConvertsHexStringWithoutHash(): void
    {
        $this->assertSame('rgba(0,0,0,1)', RGBtoRGBa('000000', 1));
    }

    // --- TimeToSec ---

    public function testTimeToSecConvertsMinutesOnlyFormat(): void
    {
        // '90' has no dots → 90 minutes * 60 = 5400 seconds
        $this->assertSame(5400, TimeToSec('90'));
    }

    public function testTimeToSecConvertsMinutesDotSecondsFormat(): void
    {
        // '1.30' → 1 minute + 30 seconds = 90 seconds
        $this->assertSame(90, TimeToSec('1.30'));
    }

    public function testTimeToSecConvertsHoursDotMinutesDotSecondsFormat(): void
    {
        // '1.30.0' → 1 hour + 30 minutes = 5400 seconds
        $this->assertSame(5400, TimeToSec('1.30.0'));
    }

    public function testTimeToSecReturnsZeroForEmptyInput(): void
    {
        $this->assertSame(0, TimeToSec(''));
    }

    // --- isEmptyDate ---

    public function testIsEmptyDateReturnsTrueForEmptyString(): void
    {
        $this->assertTrue(isEmptyDate(''));
    }

    public function testIsEmptyDateReturnsTrueForEpochSentinel(): void
    {
        // '1971-01-01 00:00:00' is the application's canonical "empty" sentinel date
        $this->assertTrue(isEmptyDate('1971-01-01 00:00:00'));
    }

    public function testIsEmptyDateReturnsFalseForRealDate(): void
    {
        $this->assertFalse(isEmptyDate('2024-06-15 14:30:00'));
    }

    // --- EpocToMysql ---

    public function testEpocToMysqlFormatsTimestampAsMysqlDatetime(): void
    {
        $epoc = mktime(12, 30, 0, 6, 15, 2024);
        $expected = date('Y-m-d H:i:s', $epoc);
        $this->assertSame($expected, EpocToMysql($epoc));
    }

    // --- Hours / Minutes ---

    public function testHoursExtractsHourComponent(): void
    {
        $this->assertSame(14, Hours('2024-06-15 14:30:00'));
    }

    public function testMinutesExtractsMinuteComponent(): void
    {
        $this->assertSame(30, Minutes('2024-06-15 14:30:00'));
    }

    // --- Date formatting ---

    public function testShortDateFormatsAsDotSeparated(): void
    {
        $this->assertSame('15.6.2024', ShortDate('2024-06-15 14:30:00'));
    }

    public function testShortEnDateFormatsAsISODate(): void
    {
        $this->assertSame('2024-06-15', ShortEnDate('2024-06-15 14:30:00'));
    }

    public function testDefHourFormatFormatsAsHoursColon(): void
    {
        $this->assertSame('14:30', DefHourFormat('2024-06-15 14:30:00'));
    }

    public function testDefTimeFormatFormatsAsDateAndTime(): void
    {
        $this->assertSame('15.6.2024 14:30', DefTimeFormat('2024-06-15 14:30:00'));
    }

    public function testDefBirthdayFormatFormatsWithLeadingZeroPadding(): void
    {
        $this->assertSame('05.03.1990', DefBirthdayFormat('1990-03-05 00:00:00'));
    }

    public function testTimeToIcalFormatsForCalendar(): void
    {
        $this->assertSame('20240615T143000', TimeToIcal('2024-06-15 14:30:00'));
    }

    public function testDateFormattingFunctionsReturnEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', ShortDate(''));
        $this->assertSame('', ShortEnDate(''));
        $this->assertSame('', DefHourFormat(''));
        $this->assertSame('', DefTimeFormat(''));
        $this->assertSame('', TimeToIcal(''));
    }

    // --- WeekdayString ---

    public function testWeekdayStringReturnsCorrectDayName(): void
    {
        // 2024-06-15 is a Saturday
        $this->assertSame('Sat', WeekdayString('2024-06-15 00:00:00', true));
    }

    public function testWeekdayStringCapTrueReturnsAbbreviatedName(): void
    {
        // 2024-06-15 is a Saturday; $cap=true → abbreviated form
        $this->assertSame('Sat', WeekdayString('2024-06-15 00:00:00', true));
    }

    public function testWeekdayStringCapFalseReturnsFullName(): void
    {
        // 2024-06-15 is a Saturday; $cap=false → full name
        $this->assertSame('Saturday', WeekdayString('2024-06-15 00:00:00', false));
    }

    public function testWeekdayStringReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', WeekdayString('', true));
    }

    // --- array_copy ---

    public function testArrayCopyProducesDeepCopyOfNestedArray(): void
    {
        $orig = ['a' => 1, 'b' => ['x' => 10, 'y' => 20]];
        $copy = array_copy($orig);

        $this->assertSame($orig, $copy);

        // Mutating the copy's nested array must not affect the original
        $copy['b']['x'] = 99;
        $this->assertSame(10, $orig['b']['x']);
    }

    // --- mergesort ---

    public function testMergesortSortsIntegerArrayInAscendingOrder(): void
    {
        $arr = [3, 1, 4, 1, 5, 9, 2, 6];
        mergesort($arr, fn($a, $b) => $a - $b);
        $this->assertSame([1, 1, 2, 3, 4, 5, 6, 9], $arr);
    }

    public function testMergesortIsStable(): void
    {
        // Items with equal sort key must keep their original relative order
        $arr = [
            ['key' => 2, 'order' => 'first'],
            ['key' => 1, 'order' => 'second'],
            ['key' => 2, 'order' => 'third'],
        ];
        mergesort($arr, fn($a, $b) => $a['key'] - $b['key']);

        $this->assertSame('second', $arr[0]['order']);
        $this->assertSame('first',  $arr[1]['order']);
        $this->assertSame('third',  $arr[2]['order']);
    }

    // --- ArrayToCsv ---

    public function testArrayToCsvReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', ArrayToCsv([], ','));
    }

    public function testArrayToCsvWritesHeaderAndDataRows(): void
    {
        $data = [
            ['name' => 'Alice', 'city' => 'Helsinki'],
            ['name' => 'Bob',   'city' => 'Tampere'],
        ];
        $csv = ArrayToCsv($data, ',');

        $lines = explode("\n", trim($csv));
        $this->assertSame('"name","city"', $lines[0]);
        $this->assertSame('"Alice","Helsinki"', $lines[1]);
        $this->assertSame('"Bob","Tampere"', $lines[2]);
    }

    // --- uo_strptime_fallback ---

    public function testUoStrptimeFallbackParsesIsoDateString(): void
    {
        $result = uo_strptime_fallback('2024-06-15', '%Y-%m-%d');

        $this->assertIsArray($result);
        $this->assertSame(124, $result['tm_year']); // 2024 - 1900
        $this->assertSame(5,   $result['tm_mon']);  // June = index 5
        $this->assertSame(15,  $result['tm_mday']);
    }

    public function testUoStrptimeFallbackReturnsFalseForInvalidMonth(): void
    {
        $result = uo_strptime_fallback('2024-13-01', '%Y-%m-%d');
        $this->assertFalse($result);
    }

    // --- GetURLBase without a configured BASEURL ---

    /**
     * A fresh process that never loaded the SUT config, so BASEURL is undefined and
     * GetURLBase() has to rebuild the URL from the request. The in-process suites
     * always have BASEURL defined and can only reach the constant branch.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetURLBaseRebuildsTheUrlFromTheRequestWhenBaseUrlIsNotConfigured(): void
    {
        $this->assertFalse(defined('BASEURL'), 'precondition: no configured base URL in this process');
        if (!function_exists('GetServerName')) {
            // lib/database.php owns this helper and is not loaded in a bootstrap-only process.
            function GetServerName()
            {
                return $_SERVER['SERVER_NAME'];
            }
        }
        $request = function (array $server, string $prefix = '') {
            $_SERVER = $server + ['SERVER_NAME' => 'example.org', 'SCRIPT_NAME' => '/app/index.php'];
            unset($_SERVER['HTTPS']);
            $_SERVER = $server + $_SERVER;
            $GLOBALS['include_prefix'] = $prefix;
            return GetURLBase();
        };

        $this->assertSame('http://example.org/app', $request(['SERVER_PORT' => '80']));
        $this->assertSame('http://example.org/app', $request([]), 'no port at all');
        $this->assertSame('http://example.org:8080/app', $request(['SERVER_PORT' => '8080']));
        $this->assertSame('https://example.org/app', $request(['HTTPS' => 'on', 'SERVER_PORT' => '443']));
        $this->assertSame('https://example.org:80/app', $request(['HTTPS' => 'on', 'SERVER_PORT' => '80']), 'port 80 is not the https default');
        $this->assertSame('http://example.org/app', $request(['HTTPS' => 'off', 'SERVER_PORT' => '80']));
        $this->assertSame('http://example.org:8080/app', $request(['SERVER_NAME' => 'example.org:8080', 'SERVER_PORT' => '8080']), 'a port already in the name is not repeated');

        $deep = ['SCRIPT_NAME' => '/a/b/c/page.php'];
        $this->assertSame('http://example.org/a/b/c', $request($deep));
        $this->assertSame('http://example.org/a/b', $request($deep, '../'));
        $this->assertSame('http://example.org/a', $request($deep, '../../'));
        $this->assertSame('http://example.org/a/b/c', $request($deep, 'lib/'), 'a prefix without ../ trims nothing');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetURLBaseIgnoresAnEmptyConfiguredBaseUrl(): void
    {
        define('BASEURL', '/');
        $_SERVER = ['SERVER_NAME' => 'example.org', 'SCRIPT_NAME' => '/app/index.php'];
        if (!function_exists('GetServerName')) {
            function GetServerName()
            {
                return $_SERVER['SERVER_NAME'];
            }
        }
        $GLOBALS['include_prefix'] = '';
        $this->assertSame('http://example.org/app', GetURLBase(), 'a base URL that is only a slash counts as empty');
    }
}
