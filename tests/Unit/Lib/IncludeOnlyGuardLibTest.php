<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class IncludeOnlyGuardLibTest extends TestCase
{
    private function runPhpSnippet(string $code): array
    {
        $stdout = fopen('php://temp', 'w+');
        $stderr = fopen('php://temp', 'w+');
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [
                0 => ['pipe', 'r'],
                1 => $stdout,
                2 => $stderr,
            ],
            $pipes
        );

        if (!is_resource($process)) {
            $this->fail('Could not start php subprocess for include_only.guard.php');
        }

        fclose($pipes[0]);
        $exitCode = proc_close($process);
        rewind($stdout);
        rewind($stderr);

        return [
            'exit_code' => $exitCode,
            'stdout' => stream_get_contents($stdout),
            'stderr' => stream_get_contents($stderr),
        ];
    }

    public function testDirectAccessPathExitsBeforeAfterMarkerAndSets404ResponseCode(): void
    {
        $target = LegacyApp::sutRoot() . '/lib/include_only.guard.php';
        $code = <<<'PHP'
register_shutdown_function(static function (): void {
    echo 'shutdown-code=' . http_response_code();
});
$_SERVER['SCRIPT_FILENAME'] = %s;
require %s;
echo 'after-marker';
PHP;

        $result = $this->runPhpSnippet(sprintf($code, var_export($target, true), var_export($target, true)));

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertStringContainsString('shutdown-code=404', $result['stdout']);
        $this->assertStringNotContainsString('after-marker', $result['stdout']);
    }

    public function testIncludedGuardFileDoesNotBlockNonDirectCaller(): void
    {
        $target = LegacyApp::sutRoot() . '/lib/include_only.guard.php';
        $code = <<<'PHP'
$_SERVER['SCRIPT_FILENAME'] = %s;
require %s;
echo 'after-marker';
PHP;

        $result = $this->runPhpSnippet(sprintf($code, var_export('/tmp/not-the-guard.php', true), var_export($target, true)));

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame('after-marker', $result['stdout']);
    }

    // In-process tests: load the file and call its functions to get PCOV coverage.
    // The subprocess tests above verify the die() path; these cover the early-return paths.

    public function testDenyDirectFileAccessReturnsEarlyWhenScriptFilenameIsDifferent(): void
    {
        // The function is defined once all SUT lib files load. If not yet loaded, load it.
        $guardFile = LegacyApp::sutRoot() . '/lib/include_only.guard.php';
        if (!function_exists('denyDirectFileAccess')) {
            require_once $guardFile;
        }
        // Pin SCRIPT_FILENAME to a real, resolvable file that differs from the
        // guarded file so both realpath() calls succeed and differ, exercising the
        // early-return branch. Relying on the ambient SCRIPT_FILENAME is unsafe:
        // under `vendor/bin/phpunit` it is the relative string 'vendor/bin/phpunit'
        // whose realpath() is false, which collides with realpath() of a
        // non-existent file and makes the guard exit() the whole test process.
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = __FILE__;
        try {
            denyDirectFileAccess($guardFile);
            $this->assertTrue(true);
        } finally {
            if ($original === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $original;
            }
        }
    }

    public function testDenyDirectLibAccessReturnsEarlyWhenScriptFilenameIsDifferent(): void
    {
        $guardFile = LegacyApp::sutRoot() . '/lib/include_only.guard.php';
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = __FILE__;
        try {
            denyDirectLibAccess($guardFile);
            $this->assertTrue(true);
        } finally {
            if ($original === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $original;
            }
        }
    }

    public function testDenyDirectFileAccessReturnsEarlyWhenScriptFilenameEmpty(): void
    {
        $original = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = '';
        try {
            denyDirectFileAccess('/tmp/some_file.php');
            $this->assertTrue(true);
        } finally {
            if ($original === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $original;
            }
        }
    }
}
