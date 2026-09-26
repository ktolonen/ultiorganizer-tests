<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Request parameters echoed back into public pages.
 *
 * defensestatus.php builds its column-sort links from the raw `pools` request
 * parameter, so markup in the parameter lands in the page. scorestatus.php
 * builds the same links from the same parameter after reducing it to integer
 * pool ids. Anonymous requests, fixture ids, and only the injected marker is
 * asserted, so the test is locale-independent.
 */
final class ReflectedParameterEscapingTest extends TestCase
{
    private const PAYLOAD = "200'\"><x-harness-reflected>";

    public function testScoreStatusDropsMarkupInPools(): void
    {
        $body = self::get('/index.php?view=scorestatus&pools=' . rawurlencode(self::PAYLOAD));

        $this->assertStringContainsString('thsort', $body, 'the sort links rendered');
        $this->assertStringNotContainsString('<x-harness-reflected>', $body);
    }

    public function testDefenseStatusDropsMarkupInPools(): void
    {
        $body = self::get('/index.php?view=defensestatus&pools=' . rawurlencode(self::PAYLOAD));

        $this->assertStringContainsString('thsort', $body, 'the sort links rendered');
        $this->assertStringNotContainsString('<x-harness-reflected>', $body);
    }

    private static function get(string $path): string
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '', $path);
        return (string) $body;
    }
}
