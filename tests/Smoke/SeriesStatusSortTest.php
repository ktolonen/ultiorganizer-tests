<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * seriesstatus.php sorts its team table by the `sort` request parameter.
 *
 * The comparator indexes each team row with the raw parameter, so a key the
 * page does not compute (including `pool`, whose column is commented out but
 * still named in the ascending-sort branch) prints PHP warnings into the page
 * for anonymous visitors.
 */
final class SeriesStatusSortTest extends TestCase
{
    public function testLinkedSortKeyRendersCleanly(): void
    {
        // Contrast: a key the page links to itself.
        $this->assertSame(0, self::phpWarnings('wins'));
    }

    public function testUnknownSortKeyRendersCleanly(): void
    {
        $this->assertSame(0, self::phpWarnings('pool'));
        $this->assertSame(0, self::phpWarnings('nosuchcolumn'));
    }

    private static function phpWarnings(string $sort): int
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = (string) file_get_contents(
            $baseUrl . '/index.php?view=seriesstatus&series=100&sort=' . rawurlencode($sort),
            false,
            $context,
        );
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');
        self::assertStringContainsString('Helsinki Heat', $body, 'the team table rendered');
        return preg_match_all('/<b>(Warning|Notice|Deprecated)<\/b>:/', $body);
    }
}
