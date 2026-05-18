<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportEndpointsContractTest extends TestCase
{
    #[DataProvider('csvExportProvider')]
    public function testCsvExportParsesWithFixtureRows(string $endpoint, array $expectedHeaders, array $expectedCells): void
    {
        $response = self::httpGet('/ext/' . $endpoint . '?Season=HRN2026&Enc=UTF-8&Sep=%2C');

        self::assertSuccessfulExportResponse($endpoint, $response, 'text/x-csv');

        $rows = self::parseCsvRows($response['body']);
        $this->assertGreaterThanOrEqual(2, count($rows), $endpoint . ' should include a header and at least one data row');
        $this->assertSame($expectedHeaders, $rows[0]);

        foreach ($expectedCells as $cell) {
            $this->assertStringContainsString($cell, $response['body'], $endpoint . ' should include fixture value ' . $cell);
        }
    }

    public function testLocationJsonExportIsParseableAndContainsFixtureLocation(): void
    {
        $response = self::httpGet('/ext/locationjson.php');

        self::assertSuccessfulExportResponse('locationjson.php', $response, 'text/plain');

        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);
        $location = self::firstRowWithValue($decoded, 'id', 400);
        $this->assertNotNull($location);
        $this->assertSame('Harness Field Complex', $location['name']);
    }

    public function testLocationXmlExportIsParseableAndContainsFixtureLocation(): void
    {
        $response = self::httpGet('/ext/locationxml.php');

        self::assertSuccessfulExportResponse('locationxml.php', $response, 'text/xml');

        $xml = simplexml_load_string($response['body']);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('markers', $xml->getName());
        $marker = $xml->xpath("//marker[@id='400']");
        $this->assertIsArray($marker);
        $this->assertNotEmpty($marker);
        $this->assertSame('Harness Field Complex', (string) $marker[0]['name']);
    }

    public function testRssExportIsParseableAndContainsFixtureResult(): void
    {
        $response = self::httpGet('/ext/rss.php?feed=gameresults&id1=HRN2026');

        self::assertSuccessfulExportResponse('rss.php', $response, 'text/xml');

        $xml = simplexml_load_string($response['body']);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('rss', $xml->getName());
        $this->assertStringContainsString('Ultimate results', (string) $xml->channel->title);
        $this->assertStringContainsString('Helsinki Heat - Tampere Tempest 15 - 11', $response['body']);
    }

    public static function csvExportProvider(): array
    {
        return [
            'teamscsv' => [
                'teamscsv.php',
                ['Team', 'ShortName', 'Club', 'Country', 'Division', 'Pool', 'Games', 'Wins', 'GoalsFor', 'GoalsAgainst', 'SpiritPoints'],
                ['Helsinki Heat', 'Tampere Tempest', 'Open', 'Pool A'],
            ],
            'gamescsv' => [
                'gamescsv.php',
                [
                    'Time',
                    'HomeSchedulingName',
                    'AwaySchedulingName',
                    'HomeTeam',
                    'AwayTeam',
                    'HomeScores',
                    'VisitorScores',
                    'Pool',
                    'Division',
                    'Field',
                    'ReservationGroup',
                    'Place',
                    'GameName',
                ],
                ['Helsinki Heat', 'Tampere Tempest', 'Harness Field Complex', 'Round 1'],
            ],
            'resultscsv' => [
                'resultscsv.php',
                ['Home', 'Away', 'HomeScores', 'AwayScores', 'Division', 'Pool'],
                ['Helsinki Heat', 'Tampere Tempest', '15', '11'],
            ],
            'poolscsv' => [
                'poolscsv.php',
                ['Division', 'Pool', 'Standing', 'Team', 'Games', 'Wins', 'Losses', 'GoalsFor', 'GoalsAgainst', 'GoalsDiff'],
                ['Open', 'Pool A', 'Helsinki Heat', 'Tampere Tempest'],
            ],
            'playerscsv' => [
                'playerscsv.php',
                [
                    'FirstName',
                    'LastName',
                    'Jersey',
                    'TeamName',
                    'TeamAbbreviation',
                    'Club',
                    'Division',
                    'Country',
                    'Games',
                    'Assists',
                    'Goals',
                    'Callahans',
                    'Total',
                ],
                ['Ari', 'Ace', 'Bea', 'Blade', 'Helsinki Heat'],
            ],
        ];
    }

    private static function httpGet(string $path): array
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

        $response = file_get_contents($baseUrl . $path, false, $context);
        $headers = $http_response_header ?? [];
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

        return [
            'body' => $body,
            'headers' => $headers,
            'status_code' => self::parseStatusCode($headers[0] ?? ''),
            'status_line' => $headers[0] ?? '',
            'content_type' => self::headerValue($headers, 'Content-Type'),
            'log_excerpt' => $logExcerpt,
            'request_path' => $path,
            'response_failed' => $response === false,
        ];
    }

    private static function assertSuccessfulExportResponse(string $id, array $response, string $expectedContentType): void
    {
        $bodyIssue = preg_match('/Fatal error|Parse error|Warning|Notice/i', $response['body']) === 1;
        $logIssue = preg_match('/PHP (Fatal error|Parse error|Warning|Notice)/i', $response['log_excerpt']) === 1;

        if ($response['response_failed'] || $response['status_code'] !== 200 || $bodyIssue || $logIssue) {
            self::fail('EXPORT_FAILURE: ' . json_encode([
                'id' => $id,
                'request_path' => $response['request_path'],
                'status_code' => $response['status_code'],
                'status_line' => $response['status_line'],
                'content_type' => $response['content_type'],
                'response_snippet' => self::snippet($response['body']),
                'log_excerpt' => self::snippet($response['log_excerpt']),
            ], JSON_UNESCAPED_SLASHES));
        }

        self::assertSame(200, $response['status_code']);
        self::assertStringContainsString($expectedContentType, strtolower($response['content_type']));
    }

    private static function parseCsvRows(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    private static function firstRowWithValue(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row[$key]) && (string) $row[$key] === (string) $value) {
                return $row;
            }
        }
        return null;
    }

    private static function parseStatusCode(string $statusLine): int
    {
        if (preg_match('/\s(\d{3})\b/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }
        return 0;
    }

    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }

    private static function snippet(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, 400);
    }
}
