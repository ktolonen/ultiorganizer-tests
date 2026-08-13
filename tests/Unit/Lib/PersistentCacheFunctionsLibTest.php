<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// Shims for configuration functions not loaded in bootstrap_only.
// Mirror the real IsPersistentCacheEnabled (configuration.functions.php) so
// tests can toggle it by writing $serverConf['PersistentCacheEnabled'].
if (!function_exists('IsPersistentCacheEnabled')) {
    function IsPersistentCacheEnabled(): bool
    {
        global $serverConf;
        return !isset($serverConf['PersistentCacheEnabled'])
            || $serverConf['PersistentCacheEnabled'] === 'true';
    }
}
if (!function_exists('GetPersistentCacheTtlSeconds')) {
    function GetPersistentCacheTtlSeconds(): int
    {
        return 5;
    }
}

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
        // DisablePersistentCacheForRequest() sets a $GLOBALS flag for the rest of
        // the (whole PHPUnit) process; unset it so it doesn't leak into other tests.
        unset($GLOBALS['uo_persistent_cache_bypass']);
    }

    private function tmpPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uo_pc_test_');
        unlink($path); // remove so PersistentCacheWrite creates it fresh
        $this->tempFiles[] = $path;
        return $path;
    }

    /** Register a cache-dir path (from PersistentCacheFilePath) for tearDown cleanup. */
    private function registerCachePath(string $namespace, mixed $key): ?string
    {
        $path = PersistentCacheFilePath($namespace, $key);
        if ($path !== null) {
            $this->tempFiles[] = $path;
            $this->tempFiles[] = $path . '.tmp.' . getmypid();
        }
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

    // --- CacheRememberFor ---

    public function testCacheRememberForCallsResolverOnCacheMiss(): void
    {
        $path = $this->registerCachePath('uo_unit_miss', 'k1');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        $calls = 0;
        $result = CacheRememberFor('uo_unit_miss', 'k1', 10, function () use (&$calls) {
            $calls++;
            return 'fresh_value';
        });
        $this->assertSame('fresh_value', $result);
        $this->assertSame(1, $calls);
    }

    public function testCacheRememberForWritesCacheFileOnMiss(): void
    {
        $path = $this->registerCachePath('uo_unit_write', 'k2');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        CacheRememberFor('uo_unit_write', 'k2', 10, fn() => 'stored');
        $this->assertFileExists($path);
        $data = PersistentCacheRead($path);
        $this->assertSame('stored', $data['payload']);
    }

    public function testCacheRememberForReturnsCachedValueWithoutCallingResolver(): void
    {
        $path = $this->registerCachePath('uo_unit_hit', 'k3');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        PersistentCacheWrite($path, 'cached_value', 60);
        $calls = 0;
        $result = CacheRememberFor('uo_unit_hit', 'k3', 10, function () use (&$calls) {
            $calls++;
            return 'fresh';
        });
        $this->assertSame('cached_value', $result);
        $this->assertSame(0, $calls);
    }

    public function testCacheRememberForRecomputesExpiredEntry(): void
    {
        $path = $this->registerCachePath('uo_unit_exp', 'k4');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        file_put_contents($path, serialize(['expires' => time() - 1, 'payload' => 'stale']));
        $result = CacheRememberFor('uo_unit_exp', 'k4', 10, fn() => 'recomputed');
        $this->assertSame('recomputed', $result);
    }

    public function testCacheRememberForUsesExplicitTtlForNewEntry(): void
    {
        $path = $this->registerCachePath('uo_unit_ttl', 'k5');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        $before = time();
        CacheRememberFor('uo_unit_ttl', 'k5', 120, fn() => 'val');
        $data = PersistentCacheRead($path);
        $this->assertGreaterThanOrEqual($before + 120, $data['expires']);
    }

    public function testCacheRememberForUsesDefaultTtlWhenZeroPassed(): void
    {
        $path = $this->registerCachePath('uo_unit_dttl', 'k6');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        $before = time();
        CacheRememberFor('uo_unit_dttl', 'k6', 0, fn() => 'v');
        $data = PersistentCacheRead($path);
        // GetPersistentCacheTtlSeconds() returns 5 in the shim
        $this->assertGreaterThanOrEqual($before + 5, $data['expires']);
    }

    public function testCacheRememberForPreservesNullPayload(): void
    {
        $path = $this->registerCachePath('uo_unit_null', 'k7');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        $result = CacheRememberFor('uo_unit_null', 'k7', 10, fn() => null);
        $this->assertNull($result);
        // Second call must return null from cache without invoking resolver again
        $calls = 0;
        $result2 = CacheRememberFor('uo_unit_null', 'k7', 10, function () use (&$calls) {
            $calls++;
            return 'not_null';
        });
        $this->assertNull($result2);
        $this->assertSame(0, $calls);
    }

    // --- CacheRememberFor $shouldStore admission callback ---
    //
    // DBQueryToValue/Array/Row pass an admission callback so an empty query
    // result is never persisted: an emptily-cached read would keep serving
    // nothing for the whole TTL after the row it looks for appears.

    public function testCacheRememberForSkipsWriteWhenShouldStoreRejectsValue(): void
    {
        $path = $this->registerCachePath('uo_unit_admit_skip', 'k_skip');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);

        $result = CacheRememberFor(
            'uo_unit_admit_skip',
            'k_skip',
            10,
            fn() => [],
            static fn($value) => $value !== [],
        );

        $this->assertSame([], $result);
        $this->assertFileDoesNotExist($path);
    }

    public function testCacheRememberForStoresValueWhenShouldStoreAcceptsIt(): void
    {
        // Contrast for the skip test above: the same callback shape must still
        // persist an accepted value, so the missing file there is the admission
        // decision and not caching being broken outright.
        $path = $this->registerCachePath('uo_unit_admit_keep', 'k_keep');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);

        $result = CacheRememberFor(
            'uo_unit_admit_keep',
            'k_keep',
            10,
            fn() => ['row'],
            static fn($value) => $value !== [],
        );

        $this->assertSame(['row'], $result);
        $this->assertFileExists($path);
        $this->assertSame(['row'], PersistentCacheRead($path)['payload']);
    }

    public function testCacheRememberForReResolvesRejectedValueOnEveryCall(): void
    {
        $path = $this->registerCachePath('uo_unit_admit_recall', 'k_recall');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;
            // Second call finds the row that the first call missed.
            return $calls === 1 ? [] : ['row'];
        };
        $admit = static fn($value) => $value !== [];

        $this->assertSame([], CacheRememberFor('uo_unit_admit_recall', 'k_recall', 10, $resolver, $admit));
        // A stored empty result would short-circuit this call and keep $calls at 1.
        $this->assertSame(['row'], CacheRememberFor('uo_unit_admit_recall', 'k_recall', 10, $resolver, $admit));
        $this->assertSame(2, $calls);
    }

    public function testCacheRememberForRemovesExpiredEntryWhenShouldStoreRejectsValue(): void
    {
        // A previously populated query can become empty. The expired entry must be
        // unlinked rather than left behind for PersistentCacheStats/CacheWipe to
        // keep tripping over.
        $path = $this->registerCachePath('uo_unit_admit_stale', 'k_stale');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        file_put_contents($path, serialize(['expires' => time() - 1, 'payload' => ['old']]));
        $this->assertFileExists($path);

        $result = CacheRememberFor(
            'uo_unit_admit_stale',
            'k_stale',
            10,
            fn() => [],
            static fn($value) => $value !== [],
        );

        $this->assertSame([], $result);
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '.lock');
    }

    public function testCacheRememberForKeepsFreshEntryWithoutConsultingShouldStore(): void
    {
        // A live entry returns before the resolver runs, so the admission callback
        // never sees it — an unexpired empty entry stays served until it expires.
        $path = $this->registerCachePath('uo_unit_admit_fresh', 'k_fresh');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        PersistentCacheWrite($path, [], 60);

        $admitCalls = 0;
        $resolverCalls = 0;
        $result = CacheRememberFor(
            'uo_unit_admit_fresh',
            'k_fresh',
            10,
            function () use (&$resolverCalls) {
                $resolverCalls++;
                return ['row'];
            },
            static function ($value) use (&$admitCalls) {
                $admitCalls++;
                return $value !== [];
            },
        );

        $this->assertSame([], $result);
        $this->assertSame(0, $resolverCalls);
        $this->assertSame(0, $admitCalls);
        $this->assertFileExists($path);
    }

    public function testCacheRememberForStoresValueWhenNoShouldStoreCallbackGiven(): void
    {
        // Callers that pass no admission callback keep the old unconditional-write
        // behaviour, including for empty values.
        $path = $this->registerCachePath('uo_unit_admit_default', 'k_default');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);

        $this->assertSame([], CacheRememberFor('uo_unit_admit_default', 'k_default', 10, fn() => []));
        $this->assertFileExists($path);
        $this->assertSame([], PersistentCacheRead($path)['payload']);
    }

    public function testCacheRememberForAdmissionCallbackReceivesResolvedValue(): void
    {
        $path = $this->registerCachePath('uo_unit_admit_arg', 'k_arg');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);

        $seen = 'not-called';
        CacheRememberFor(
            'uo_unit_admit_arg',
            'k_arg',
            10,
            fn() => 'resolved-value',
            static function ($value) use (&$seen) {
                $seen = $value;
                return true;
            },
        );

        $this->assertSame('resolved-value', $seen);
    }

    // --- CacheForgetPersistent ---

    public function testCacheForgetPersistentDeletesSpecificEntry(): void
    {
        $path = $this->registerCachePath('uo_unit_forget', 'fk1');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        PersistentCacheWrite($path, 'value', 60);
        $this->assertFileExists($path);
        CacheForgetPersistent('uo_unit_forget', 'fk1');
        $this->assertFileDoesNotExist($path);
    }

    public function testCacheForgetPersistentWithNullKeyWipesNamespace(): void
    {
        $path1 = PersistentCacheFilePath('uo_unit_ns_wipe', 'wk1');
        $path2 = PersistentCacheFilePath('uo_unit_ns_wipe', 'wk2');
        if ($path1 === null || $path2 === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        $this->tempFiles[] = $path1;
        $this->tempFiles[] = $path2;
        PersistentCacheWrite($path1, 'v1', 60);
        PersistentCacheWrite($path2, 'v2', 60);
        CacheForgetPersistent('uo_unit_ns_wipe');
        $this->assertFileDoesNotExist($path1);
        $this->assertFileDoesNotExist($path2);
    }

    public function testCacheForgetPersistentWithNullKeyIsNoopWhenNoFiles(): void
    {
        // Should not throw; just wipes zero matching files
        CacheForgetPersistent('uo_unit_ns_empty_' . uniqid());
        $this->assertTrue(true);
    }

    // --- PersistentCacheStats ---

    public function testPersistentCacheStatsReflectsWrittenFiles(): void
    {
        $path = $this->registerCachePath('uo_unit_stats', 'sk1');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        @unlink($path);
        $before = PersistentCacheStats();
        PersistentCacheWrite($path, 'payload_data', 60);
        $after = PersistentCacheStats();
        $this->assertSame($before['files'] + 1, $after['files']);
        $this->assertGreaterThan($before['bytes'], $after['bytes']);
    }

    public function testPersistentCacheStatsDecreasesAfterDeletion(): void
    {
        $path = $this->registerCachePath('uo_unit_stats2', 'sk2');
        if ($path === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        PersistentCacheWrite($path, 'data', 60);
        $before = PersistentCacheStats();
        @unlink($path);
        $after = PersistentCacheStats();
        $this->assertSame($before['files'] - 1, $after['files']);
    }

    // --- CacheWipePersistent ---

    public function testCacheWipePersistentDeletesAllCacheFiles(): void
    {
        $path1 = $this->registerCachePath('uo_unit_wipe', 'wipe1');
        $path2 = $this->registerCachePath('uo_unit_wipe', 'wipe2');
        if ($path1 === null || $path2 === null) {
            $this->markTestSkipped('PersistentCacheDir not available');
        }
        PersistentCacheWrite($path1, 'a', 60);
        PersistentCacheWrite($path2, 'b', 60);
        $count = CacheWipePersistent();
        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertFileDoesNotExist($path1);
        $this->assertFileDoesNotExist($path2);
    }

    public function testCacheWipePersistentReturnsZeroWhenNoCacheFiles(): void
    {
        // Ensure cache is empty first, then wipe again
        CacheWipePersistent();
        $count = CacheWipePersistent();
        $this->assertSame(0, $count);
    }

    // --- Cache-disabled path (line 32) ---

    public function testCacheRememberForBypassesCacheWhenDisabled(): void
    {
        global $serverConf;
        $prev = $serverConf['PersistentCacheEnabled'] ?? null;
        $serverConf['PersistentCacheEnabled'] = 'false';
        try {
            $calls = 0;
            $result = CacheRememberFor('uo_unit_disabled', 'k_dis', 10, function () use (&$calls) {
                $calls++;
                return 'direct';
            });
            $this->assertSame('direct', $result);
            $this->assertSame(1, $calls);
        } finally {
            if ($prev === null) {
                unset($serverConf['PersistentCacheEnabled']);
            } else {
                $serverConf['PersistentCacheEnabled'] = $prev;
            }
        }
    }

    // --- PERSISTENT_CACHE_DIR constant paths (lines 173, 176) ---
    // Must run in a separate process so we can define the constant fresh.

    /**
     * @runInSeparateProcess
     */
    public function testCacheDirRespectsEmptyPersistentCacheDirConstant(): void
    {
        LegacyApp::loadLibFileUsingProfile('persistent-cache.functions.php', 'bootstrap_only');
        define('PERSISTENT_CACHE_DIR', '');
        // PersistentCacheDir: PERSISTENT_CACHE_DIR defined (line 173) → empty → null (line 176).
        $this->assertNull(PersistentCacheFilePath('ns', 'k'));
        $this->assertSame(['files' => 0, 'bytes' => 0], PersistentCacheStats());
        $count = CacheWipePersistent();
        $this->assertSame(0, $count);
    }

    // --- Null cache-dir paths: block the dir with a file so PersistentCacheDir
    //     returns null. Must run after all tests that need a working cache dir. ---

    public function testCacheFunctionsHandleNullCacheDir(): void
    {
        // Determine the dir PersistentCacheDir() would create in this context
        // (PERSISTENT_CACHE_DIR undefined → fallback base, instance key = '||').
        $base = sys_get_temp_dir() . '/ultiorganizer-cache';
        $dir  = $base . '/' . md5('||');

        // Clear any existing cache files so we can rmdir safely.
        CacheWipePersistent();

        // Block: delete the hash dir and plant a file in its place.
        $blocked = false;
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        if (!is_dir($dir)) {
            @mkdir($base, 0700, true);
            if (@touch($dir)) {
                $blocked = true;
            }
        }

        if (!$blocked) {
            $this->markTestSkipped('Could not block PersistentCacheDir for null-path test');
        }

        try {
            // PersistentCacheDir() → is_dir=false, mkdir fails → returns null.
            // CacheForgetPersistent with null key → hits line 104.
            CacheForgetPersistent('ns_block');
            // PersistentCacheStats → hits line 127.
            $stats = PersistentCacheStats();
            $this->assertSame(['files' => 0, 'bytes' => 0], $stats);
            // CacheWipePersistent → hits line 150.
            $count = CacheWipePersistent();
            $this->assertSame(0, $count);
            // CacheRememberFor → PersistentCacheFilePath returns null → line 38.
            $result = CacheRememberFor('ns_block', 'k', 10, fn() => 'fallback');
            $this->assertSame('fallback', $result);
        } finally {
            // Restore: remove the blocking file, recreate the cache dir.
            @unlink($dir);
            @mkdir($dir, 0700, true);
        }
    }

    // --- DisablePersistentCacheForRequest / IsPersistentCacheBypassed ---

    public function testIsPersistentCacheBypassedDefaultsToFalse(): void
    {
        $this->assertFalse(IsPersistentCacheBypassed());
    }

    public function testDisablePersistentCacheForRequestSetsBypassed(): void
    {
        $this->assertFalse(IsPersistentCacheBypassed());

        DisablePersistentCacheForRequest();

        $this->assertTrue(IsPersistentCacheBypassed());
    }
}
