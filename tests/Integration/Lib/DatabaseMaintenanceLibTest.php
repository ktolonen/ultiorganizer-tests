<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class DatabaseMaintenanceLibTest extends TestCase
{
    // Covers all testable functions in database.maintenance.php.
    // DBRenderMaintenanceResponse(), DBRunAutomaticUpgrade(), and
    // DBHandleMaintenanceState() call exit() and are intentionally excluded.

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // database_only loads database.php which defines DB_VERSION and
        // DB_MAINTENANCE_LOCK_TIMEOUT before database.maintenance.php is included.
        LegacyApp::loadLibFilesUsingProfile(['database.maintenance.php'], 'database_only');

        DBEnsureMaintenanceRuntimeDir();
        @unlink(DBMaintenanceFlagPath());
        @unlink(DBMaintenanceLockPath());
    }

    protected function tearDown(): void
    {
        @unlink(DBMaintenanceFlagPath());
        @unlink(DBMaintenanceLockPath());
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_ACCEPT']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- DBTimestamp ---

    public function testDBTimestampReturnsIso8601String(): void
    {
        $ts = DBTimestamp();
        $this->assertIsString($ts);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $ts);
    }

    // --- DBHtmlEscape ---

    public function testDBHtmlEscapeEscapesHtmlChars(): void
    {
        $this->assertSame('&lt;b&gt;Test&lt;/b&gt;', DBHtmlEscape('<b>Test</b>'));
    }

    public function testDBHtmlEscapeHandlesDoubleQuotes(): void
    {
        $this->assertSame('&quot;Hi&quot;', DBHtmlEscape('"Hi"'));
    }

    public function testDBHtmlEscapeCoercesNonString(): void
    {
        $this->assertSame('42', DBHtmlEscape(42));
    }

    // --- DBAutomaticMaintenancePayload ---

    public function testDBAutomaticMaintenancePayloadPendingHasThreeLines(): void
    {
        $payload = DBAutomaticMaintenancePayload('pending', DB_VERSION);
        $lines = explode("\n", rtrim($payload, "\n"));
        $this->assertCount(3, $lines);
        $this->assertSame('mode=automatic', $lines[0]);
        $this->assertSame('status=pending', $lines[1]);
        $this->assertSame('target=' . DB_VERSION, $lines[2]);
    }

    public function testDBAutomaticMaintenancePayloadRunningHasFourLines(): void
    {
        $payload = DBAutomaticMaintenancePayload('running', DB_VERSION, ['started_at' => '2026-01-01T00:00:00+00:00']);
        $lines = explode("\n", rtrim($payload, "\n"));
        $this->assertCount(4, $lines);
        $this->assertSame('started_at=2026-01-01T00:00:00+00:00', $lines[3]);
    }

    public function testDBAutomaticMaintenancePayloadRunningUsesDefaultTimestampWhenMetaOmitted(): void
    {
        $payload = DBAutomaticMaintenancePayload('running', DB_VERSION);
        $lines = explode("\n", rtrim($payload, "\n"));
        $this->assertCount(4, $lines);
        $this->assertStringStartsWith('started_at=', $lines[3]);
    }

    public function testDBAutomaticMaintenancePayloadFailedHasFiveLines(): void
    {
        $payload = DBAutomaticMaintenancePayload('failed', DB_VERSION, [
            'failed_at' => '2026-01-01T00:00:00+00:00',
            'error'     => 'Something went wrong',
        ]);
        $lines = explode("\n", rtrim($payload, "\n"));
        $this->assertCount(5, $lines);
        $this->assertSame('failed_at=2026-01-01T00:00:00+00:00', $lines[3]);
        $this->assertSame('error=Something went wrong', $lines[4]);
    }

    // --- DBParseAutomaticMaintenanceFlag ---

    public function testParseEmptyStringReturnsManualEmpty(): void
    {
        $result = DBParseAutomaticMaintenanceFlag('');
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('empty', $result['reason']);
    }

    public function testParseValidPendingPayloadReturnsAutomatic(): void
    {
        $payload = DBAutomaticMaintenancePayload('pending', DB_VERSION);
        $result  = DBParseAutomaticMaintenanceFlag($payload);
        $this->assertSame('automatic', $result['mode']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame(DB_VERSION, $result['target']);
        $this->assertNull($result['started_at']);
        $this->assertNull($result['failed_at']);
        $this->assertNull($result['error']);
    }

    public function testParseValidRunningPayloadReturnsAutomatic(): void
    {
        $ts      = DBTimestamp();
        $payload = DBAutomaticMaintenancePayload('running', DB_VERSION, ['started_at' => $ts]);
        $result  = DBParseAutomaticMaintenanceFlag($payload);
        $this->assertSame('automatic', $result['mode']);
        $this->assertSame('running', $result['status']);
        $this->assertSame($ts, $result['started_at']);
    }

    public function testParseValidFailedPayloadReturnsAutomatic(): void
    {
        $ts      = DBTimestamp();
        $payload = DBAutomaticMaintenancePayload('failed', DB_VERSION, [
            'failed_at' => $ts,
            'error'     => 'Upgrade failed',
        ]);
        $result = DBParseAutomaticMaintenanceFlag($payload);
        $this->assertSame('automatic', $result['mode']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('Upgrade failed', $result['error']);
    }

    public function testParseTwoLinesReturnsManualLineCount(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("line1\nline2");
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('line_count', $result['reason']);
    }

    public function testParseSixLinesReturnsManualLineCount(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("a\nb\nc\nd\ne\nf");
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('line_count', $result['reason']);
    }

    public function testParseMalformedLineNoEqualsReturnsManualMalformed(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("noequals\nstatus=pending\ntarget=" . DB_VERSION);
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('malformed', $result['reason']);
    }

    public function testParseWrongKeyNameReturnsManualUnexpectedKey(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("wrong=automatic\nstatus=pending\ntarget=" . DB_VERSION);
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('unexpected_key', $result['reason']);
    }

    public function testParseNonAutomaticModeReturnsManualInvalidMode(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("mode=manual\nstatus=pending\ntarget=" . DB_VERSION);
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_mode', $result['reason']);
    }

    public function testParseInvalidStatusReturnsManualInvalidStatus(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("mode=automatic\nstatus=unknown\ntarget=" . DB_VERSION);
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_status', $result['reason']);
    }

    public function testParseNonNumericTargetReturnsManualInvalidTarget(): void
    {
        $result = DBParseAutomaticMaintenanceFlag("mode=automatic\nstatus=pending\ntarget=notanumber");
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_target', $result['reason']);
    }

    public function testParseWrongVersionTargetReturnsManualInvalidTarget(): void
    {
        $wrongVersion = DB_VERSION + 100;
        $result = DBParseAutomaticMaintenanceFlag("mode=automatic\nstatus=pending\ntarget={$wrongVersion}");
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_target', $result['reason']);
    }

    public function testParsePendingWithFourLinesReturnsManualInvalidPending(): void
    {
        // pending expects exactly 3 lines; 4 lines → invalid_pending
        $result = DBParseAutomaticMaintenanceFlag(
            "mode=automatic\nstatus=pending\ntarget=" . DB_VERSION . "\nstarted_at=2026-01-01T00:00:00+00:00"
        );
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_pending', $result['reason']);
    }

    public function testParseRunningWithThreeLinesReturnsManualInvalidRunning(): void
    {
        // running expects 4 lines; 3 lines → invalid_running
        $result = DBParseAutomaticMaintenanceFlag("mode=automatic\nstatus=running\ntarget=" . DB_VERSION);
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_running', $result['reason']);
    }

    public function testParseFailedWithFourLinesReturnsManualInvalidFailed(): void
    {
        // failed expects 5 lines; 4 lines → invalid_failed
        $result = DBParseAutomaticMaintenanceFlag(
            "mode=automatic\nstatus=failed\ntarget=" . DB_VERSION . "\nstarted_at=2026-01-01T00:00:00+00:00"
        );
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('invalid_failed', $result['reason']);
    }

    public function testParseRunningWithEmptyStartedAtReturnsManualMissingStartedAt(): void
    {
        $result = DBParseAutomaticMaintenanceFlag(
            "mode=automatic\nstatus=running\ntarget=" . DB_VERSION . "\nstarted_at="
        );
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('missing_started_at', $result['reason']);
    }

    public function testParseFailedWithEmptyErrorReturnsManualMissingFailureMeta(): void
    {
        $result = DBParseAutomaticMaintenanceFlag(
            "mode=automatic\nstatus=failed\ntarget=" . DB_VERSION . "\nfailed_at=2026-01-01T00:00:00+00:00\nerror="
        );
        $this->assertSame('manual', $result['mode']);
        $this->assertSame('missing_failure_meta', $result['reason']);
    }

    public function testParseCrlfLineEndingsHandledCorrectly(): void
    {
        $payload = "mode=automatic\r\nstatus=pending\r\ntarget=" . DB_VERSION . "\r\n";
        $result  = DBParseAutomaticMaintenanceFlag($payload);
        $this->assertSame('automatic', $result['mode']);
        $this->assertSame('pending', $result['status']);
    }

    // --- DBMaintenanceRuntimeDir ---

    public function testDBMaintenanceRuntimeDirReturnsNonEmptyString(): void
    {
        $dir = DBMaintenanceRuntimeDir();
        $this->assertIsString($dir);
        $this->assertNotSame('', $dir);
    }

    // --- DBMaintenanceFlagPath / DBMaintenanceLockPath ---

    public function testDBMaintenanceFlagPathEndsWithFlagFilename(): void
    {
        $this->assertStringEndsWith('maintenance.flag', DBMaintenanceFlagPath());
    }

    public function testDBMaintenanceLockPathEndsWithLockFilename(): void
    {
        $this->assertStringEndsWith('maintenance.lock', DBMaintenanceLockPath());
    }

    // --- DBEnsureMaintenanceRuntimeDir ---

    public function testDBEnsureMaintenanceRuntimeDirReturnsTrueWhenExists(): void
    {
        $this->assertTrue(DBEnsureMaintenanceRuntimeDir());
    }

    public function testDBEnsureMaintenanceRuntimeDirCreatesDirectoryWhenAbsent(): void
    {
        $dir = DBMaintenanceRuntimeDir();
        // setUp already deleted flag and lock, so dir should be empty and removable.
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        // Dir is gone — function must create it via mkdir.
        $this->assertTrue(DBEnsureMaintenanceRuntimeDir());
        $this->assertDirectoryExists($dir);
    }

    // --- DBWriteMaintenanceFlag ---

    public function testDBWriteMaintenanceFlagWritesContentToFlagFile(): void
    {
        $content = "hello=world\n";
        $this->assertTrue(DBWriteMaintenanceFlag($content));
        $this->assertFileExists(DBMaintenanceFlagPath());
        $this->assertSame($content, file_get_contents(DBMaintenanceFlagPath()));
    }

    // --- DBReadMaintenanceState ---

    public function testDBReadMaintenanceStateReturnsNoneWhenNoFlagFile(): void
    {
        $state = DBReadMaintenanceState();
        $this->assertSame('none', $state['mode']);
    }

    public function testDBReadMaintenanceStateReturnsParsedStateForValidFlag(): void
    {
        DBWriteMaintenanceFlag(DBAutomaticMaintenancePayload('pending', DB_VERSION));
        $state = DBReadMaintenanceState();
        $this->assertSame('automatic', $state['mode']);
        $this->assertSame('pending', $state['status']);
    }

    public function testDBReadMaintenanceStateReturnsManualForUnrecognizedContent(): void
    {
        file_put_contents(DBMaintenanceFlagPath(), "manual maintenance mode\n");
        $state = DBReadMaintenanceState();
        $this->assertSame('manual', $state['mode']);
    }

    // --- DBCreateAutomaticMaintenanceFlag ---

    public function testDBCreateAutomaticMaintenanceFlagCreatesPendingFlag(): void
    {
        $this->assertTrue(DBCreateAutomaticMaintenanceFlag());
        $state = DBReadMaintenanceState();
        $this->assertSame('automatic', $state['mode']);
        $this->assertSame('pending', $state['status']);
    }

    // --- DBRemoveAutomaticMaintenanceFlag ---

    public function testDBRemoveAutomaticMaintenanceFlagRemovesExistingFlag(): void
    {
        DBCreateAutomaticMaintenanceFlag();
        $this->assertFileExists(DBMaintenanceFlagPath());
        $this->assertTrue(DBRemoveAutomaticMaintenanceFlag());
        $this->assertFileDoesNotExist(DBMaintenanceFlagPath());
    }

    public function testDBRemoveAutomaticMaintenanceFlagReturnsTrueWhenNoFlagExists(): void
    {
        $this->assertFileDoesNotExist(DBMaintenanceFlagPath());
        $this->assertTrue(DBRemoveAutomaticMaintenanceFlag());
    }

    // --- DBReleaseUpgradeLock ---

    public function testDBReleaseUpgradeLockRemovesExistingLockFile(): void
    {
        file_put_contents(DBMaintenanceLockPath(), 'test');
        $this->assertFileExists(DBMaintenanceLockPath());
        DBReleaseUpgradeLock();
        $this->assertFileDoesNotExist(DBMaintenanceLockPath());
    }

    public function testDBReleaseUpgradeLockIsNoOpWhenLockDoesNotExist(): void
    {
        $this->assertFileDoesNotExist(DBMaintenanceLockPath());
        DBReleaseUpgradeLock();
        $this->assertTrue(true);
    }

    // --- DBIsUpgradeLockStale ---

    public function testDBIsUpgradeLockStaleReturnsFalseWhenNoLockFile(): void
    {
        $this->assertFalse(DBIsUpgradeLockStale());
    }

    public function testDBIsUpgradeLockStaleReturnsFalseForFreshLock(): void
    {
        file_put_contents(DBMaintenanceLockPath(), 'fresh');
        $this->assertFalse(DBIsUpgradeLockStale());
    }

    public function testDBIsUpgradeLockStaleReturnsTrueForStaleLock(): void
    {
        $lockPath = DBMaintenanceLockPath();
        file_put_contents($lockPath, 'stale');
        touch($lockPath, time() - DB_MAINTENANCE_LOCK_TIMEOUT - 60);
        $this->assertTrue(DBIsUpgradeLockStale());
    }

    // --- DBTryAcquireUpgradeLock ---

    public function testDBTryAcquireUpgradeLockReturnsTrueAndCreatesFileWhenNoLock(): void
    {
        $this->assertTrue(DBTryAcquireUpgradeLock());
        $this->assertFileExists(DBMaintenanceLockPath());
    }

    public function testDBTryAcquireUpgradeLockReturnsFalseWhenFreshLockExists(): void
    {
        file_put_contents(DBMaintenanceLockPath(), 'existing');
        $this->assertFalse(DBTryAcquireUpgradeLock());
    }

    public function testDBTryAcquireUpgradeLockClearsStaleAndAcquires(): void
    {
        $lockPath = DBMaintenanceLockPath();
        file_put_contents($lockPath, 'stale');
        touch($lockPath, time() - DB_MAINTENANCE_LOCK_TIMEOUT - 60);
        $this->assertTrue(DBTryAcquireUpgradeLock());
    }

    // --- DBMaintenanceResponseData ---

    public function testDBMaintenanceResponseDataForPendingReturnsUpgradeInProgressTitle(): void
    {
        $data = DBMaintenanceResponseData(['mode' => 'automatic', 'status' => 'pending']);
        $this->assertStringContainsStringIgnoringCase('progress', $data['title']);
        $this->assertArrayHasKey('message', $data);
    }

    public function testDBMaintenanceResponseDataForRunningReturnsUpgradeInProgressTitle(): void
    {
        $data = DBMaintenanceResponseData(['mode' => 'automatic', 'status' => 'running']);
        $this->assertStringContainsStringIgnoringCase('progress', $data['title']);
    }

    public function testDBMaintenanceResponseDataForFailedReturnsFailedTitle(): void
    {
        $data = DBMaintenanceResponseData(['mode' => 'automatic', 'status' => 'failed']);
        $this->assertStringContainsStringIgnoringCase('failed', $data['title']);
    }

    public function testDBMaintenanceResponseDataForManualReturnsMaintenanceTitle(): void
    {
        $data = DBMaintenanceResponseData(['mode' => 'manual']);
        $this->assertSame('Maintenance', $data['title']);
        $this->assertArrayHasKey('message', $data);
    }

    public function testDBMaintenanceResponseDataForNonAutomaticModeReturnsMaintenanceTitle(): void
    {
        $data = DBMaintenanceResponseData(['mode' => 'none']);
        $this->assertSame('Maintenance', $data['title']);
    }

    // --- DBRequestPrefersJson ---

    public function testDBRequestPrefersJsonReturnsFalseForNormalHtmlRequest(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $this->assertFalse(DBRequestPrefersJson());
    }

    public function testDBRequestPrefersJsonReturnsTrueForApiScriptPath(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/api/v1/endpoint.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertTrue(DBRequestPrefersJson());
    }

    public function testDBRequestPrefersJsonReturnsTrueForJsonPhpScript(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/data.json.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertTrue(DBRequestPrefersJson());
    }

    public function testDBRequestPrefersJsonReturnsTrueForJsonAcceptHeader(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertTrue(DBRequestPrefersJson());
    }

    // --- DBMarkAutomaticUpgradeFailed ---

    public function testDBMarkAutomaticUpgradeFailedWritesFailedState(): void
    {
        DBMarkAutomaticUpgradeFailed('Something broke');
        $state = DBReadMaintenanceState();
        $this->assertSame('automatic', $state['mode']);
        $this->assertSame('failed', $state['status']);
        $this->assertSame('Something broke', $state['error']);
    }

    public function testDBMarkAutomaticUpgradeFailedUsesDefaultMessageWhenEmpty(): void
    {
        DBMarkAutomaticUpgradeFailed('');
        $state = DBReadMaintenanceState();
        $this->assertStringContainsString('Upgrade failed', $state['error']);
    }

    public function testDBMarkAutomaticUpgradeFailedTruncatesLongMessages(): void
    {
        DBMarkAutomaticUpgradeFailed(str_repeat('X', 250));
        $state = DBReadMaintenanceState();
        $this->assertSame(200, strlen($state['error']));
    }

    // --- DBRunAutomaticUpgrade ---

    public function testDBRunAutomaticUpgradeReturnsFalseWhenLockAlreadyHeld(): void
    {
        // Simulate another process holding the lock by writing a fresh lock file.
        file_put_contents(DBMaintenanceLockPath(), (string) time());
        $result = DBRunAutomaticUpgrade();
        $this->assertFalse($result);
    }
}
