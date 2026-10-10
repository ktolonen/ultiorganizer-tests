<?php

declare(strict_types=1);

namespace UltiorganizerHarness\Support;

/**
 * PHP issue patterns shared by the HTTP-driven suites. The log pattern lives in
 * config/php-issue-pattern.txt so scripts/container_runner.py uses the same one.
 */
final class PhpIssue
{
    public static function logPattern(): string
    {
        $raw = trim((string) file_get_contents(dirname(__DIR__, 2) . '/config/php-issue-pattern.txt'));
        return '/' . $raw . '/i';
    }

    /** Matches an issue in a log excerpt. */
    public static function inLog(string $log): bool
    {
        return preg_match(self::logPattern(), $log) === 1;
    }

    /** Matches an issue rendered into a response body. */
    public static function inBody(string $body): bool
    {
        return preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:|Uncaught /i', $body) === 1;
    }
}
