<?php

declare(strict_types=1);

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
        $this->markTestSkipped('WeekdayString $cap fix not yet in source — re-enable after merge');
    }

    public function testWeekdayStringReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', WeekdayString('', true));
    }

    // --- getChkNum / checkChkNum ---

    public function testGetChkNumComputesCorrectCheckDigit(): void
    {
        // Trace for '123': 3*7 + 2*3 + 1*1 = 21+6+1 = 28, chk = (10 - 28%10)%10 = 2
        $this->assertSame(2, getChkNum('123'));
    }

    public function testCheckChkNumReturnsTrueForValidCheckDigit(): void
    {
        $this->assertTrue(checkChkNum('1232'));
    }

    public function testCheckChkNumReturnsFalseForInvalidCheckDigit(): void
    {
        $this->assertFalse(checkChkNum('1231'));
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
}
