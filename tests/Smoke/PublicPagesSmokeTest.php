<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicPagesSmokeTest extends TestCase
{
    #[DataProvider('pageProvider')]
    public function testPublicPageRendersWithoutRuntimeErrors(string $pageId, string $query): void
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $errorLog = getenv('UO_APACHE_ERROR_LOG') ?: '/var/log/apache2/error.log';
        $beforeSize = is_file($errorLog) ? filesize($errorLog) : 0;

        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = file_get_contents($baseUrl . '/index.php?' . $query, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        $statusCode = self::parseStatusCode($statusLine);
        $body = is_string($response) ? $response : '';

        clearstatcache(true, $errorLog);
        $afterSize = is_file($errorLog) ? filesize($errorLog) : 0;
        $logExcerpt = '';
        if ($afterSize > $beforeSize && is_file($errorLog)) {
            $handle = fopen($errorLog, 'rb');
            if ($handle !== false) {
                fseek($handle, $beforeSize);
                $logExcerpt = stream_get_contents($handle) ?: '';
                fclose($handle);
            }
        }

        $runtimeIssue = preg_match('/Fatal error|Parse error|Warning|Notice/i', $body) === 1;
        $logIssue = preg_match('/PHP (Fatal error|Parse error|Warning|Notice)/i', $logExcerpt) === 1;
        if ($response === false || $statusCode !== 200 || $runtimeIssue || $logIssue) {
            $payload = [
                'page_id' => $pageId,
                'query' => $query,
                'status_code' => $statusCode,
                'status_line' => $statusLine,
                'response_snippet' => self::snippet($body),
                'log_excerpt' => self::snippet($logExcerpt),
            ];
            self::fail('SMOKE_FAILURE: ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $this->assertIsString($body);
        $this->assertSame(200, $statusCode);
    }

    public static function pageProvider(): array
    {
        $rawPages = getenv('UO_SMOKE_PAGES');
        if (!is_string($rawPages) || trim($rawPages) === '') {
            return [
                'frontpage' => ['frontpage', 'view=frontpage'],
                'seasonlist' => ['seasonlist', 'view=seasonlist'],
                'allcountries' => ['allcountries', 'view=allcountries'],
            ];
        }

        $decoded = json_decode($rawPages, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('UO_SMOKE_PAGES is not valid JSON');
        }

        $pages = [];
        foreach ($decoded as $page) {
            if (!is_array($page) || !isset($page['id'], $page['query'])) {
                throw new RuntimeException('Each smoke page must define id and query');
            }
            $pages[$page['id']] = [(string) $page['id'], (string) $page['query']];
        }
        return $pages;
    }

    private static function parseStatusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\b/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }
        return 0;
    }

    private static function snippet(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 400);
    }
}
