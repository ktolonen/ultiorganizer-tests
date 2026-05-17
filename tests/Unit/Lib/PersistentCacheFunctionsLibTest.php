<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PersistentCacheFunctionsLibTest extends TestCase
{
    /** @var string[] Temp files created during the test, cleaned up in tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('persistent-cache.functions.php', 'bootstrap_only');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
            @unlink($file . '.lock');
        }
    }

    private function tmpPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uo_pc_test_');
        unlink($path); // remove so PersistentCacheWrite creates it fresh
        $this->tempFiles[] = $path;
        return $path;
    }

    // --- PersistentCacheRead ---

    public function testPersistentCacheReadReturnsNullForNonExistentFile(): void
    {
        $this->assertNull(PersistentCacheRead('/tmp/ultiorg_nonexistent_cache_xyz.cache'));
    }

    public function testPersistentCacheReadReturnsNullForInvalidContent(): void
    {
        $path = $this->tmpPath();
        file_put_contents($path, 'not-serialized-data');
        $this->assertNull(PersistentCacheRead($path));
    }

    public function testPersistentCacheReadReturnsNullForMissingPayloadKey(): void
    {
        $path = $this->tmpPath();
        file_put_contents($path, serialize(['expires' => time() + 60]));
        $this->assertNull(PersistentCacheRead($path));
    }

    public function testPersistentCacheReadReturnsNullForMissingExpiresKey(): void
    {
        $path = $this->tmpPath();
        file_put_contents($path, serialize(['payload' => 'foo']));
        $this->assertNull(PersistentCacheRead($path));
    }

    // --- PersistentCacheWrite + PersistentCacheRead round-trip ---

    public function testWriteThenReadReturnsPayload(): void
    {
        $path = $this->tmpPath();
        PersistentCacheWrite($path, 'hello', 60);

        $data = PersistentCacheRead($path);
        $this->assertIsArray($data);
        $this->assertSame('hello', $data['payload']);
    }

    public function testWriteThenReadSetsExpiryInFuture(): void
    {
        $path = $this->tmpPath();
        $before = time();
        PersistentCacheWrite($path, 'value', 30);

        $data = PersistentCacheRead($path);
        $this->assertGreaterThanOrEqual($before + 30, $data['expires']);
    }

    public function testWriteThenReadPreservesNullPayload(): void
    {
        // A cached null (e.g. empty DB result) must survive the round-trip
        $path = $this->tmpPath();
        PersistentCacheWrite($path, null, 60);

        $data = PersistentCacheRead($path);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertNull($data['payload']);
    }

    public function testWriteThenReadPreservesArrayPayload(): void
    {
        $payload = ['team' => 'Alpha', 'score' => 42];
        $path = $this->tmpPath();
        PersistentCacheWrite($path, $payload, 60);

        $data = PersistentCacheRead($path);
        $this->assertSame($payload, $data['payload']);
    }

    // --- PersistentCacheFilePath ---

    public function testPersistentCacheFilePathContainsNamespaceInFilename(): void
    {
        $path = PersistentCacheFilePath('my_namespace', 'some_key');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available in this environment');
        }
        $this->assertStringContainsString('my_namespace', basename($path));
        $this->assertStringEndsWith('.cache', $path);
    }

    public function testPersistentCacheFilePathIsDeterministicForSameInputs(): void
    {
        $pathA = PersistentCacheFilePath('season_info', 42);
        $pathB = PersistentCacheFilePath('season_info', 42);
        $this->assertSame($pathA, $pathB);
    }

    public function testPersistentCacheFilePathDiffersForDifferentKeys(): void
    {
        $pathA = PersistentCacheFilePath('season_info', 42);
        $pathB = PersistentCacheFilePath('season_info', 99);
        $this->assertNotSame($pathA, $pathB);
    }

    public function testPersistentCacheFilePathDiffersForDifferentNamespaces(): void
    {
        $pathA = PersistentCacheFilePath('ns_one', 'key');
        $pathB = PersistentCacheFilePath('ns_two', 'key');
        $this->assertNotSame($pathA, $pathB);
    }
}
