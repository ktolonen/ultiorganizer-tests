<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CacheFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('cache.functions.php', 'bootstrap_only');
        $GLOBALS['runtime_cache'] = [];
    }

    // --- CacheRuntimeKey ---

    public function testCacheRuntimeKeyHasNamespacePrefixAndHashSuffix(): void
    {
        $key = CacheRuntimeKey('season_info', 42);
        $this->assertMatchesRegularExpression('/^season_info:[0-9a-f]{32}$/', $key);
    }

    public function testCacheRuntimeKeyIsDeterministicForSameInputs(): void
    {
        $this->assertSame(
            CacheRuntimeKey('season_info', 42),
            CacheRuntimeKey('season_info', 42)
        );
    }

    public function testCacheRuntimeKeyHashesScalarStringInput(): void
    {
        $key = CacheRuntimeKey('ns', 'hello');
        $this->assertMatchesRegularExpression('/^ns:[0-9a-f]{32}$/', $key);
    }

    public function testCacheRuntimeKeyProducesDifferentHashesForDifferentKeys(): void
    {
        $this->assertNotSame(
            CacheRuntimeKey('ns', 'hello'),
            CacheRuntimeKey('ns', 'world')
        );
    }

    public function testCacheRuntimeKeyHashesArrayKey(): void
    {
        $key = CacheRuntimeKey('ns', ['a' => 1, 'b' => 2]);
        $this->assertMatchesRegularExpression('/^ns:[0-9a-f]{32}$/', $key);
    }

    public function testCacheRuntimeKeyHashesNullInput(): void
    {
        $key = CacheRuntimeKey('ns', null);
        $this->assertMatchesRegularExpression('/^ns:[0-9a-f]{32}$/', $key);
    }

    // --- CacheRemember ---

    public function testCacheRememberCallsResolverOnFirstAccess(): void
    {
        $called = 0;
        $result = CacheRemember('test', 'key1', function () use (&$called) {
            $called++;
            return 'value1';
        });

        $this->assertSame('value1', $result);
        $this->assertSame(1, $called);
    }

    public function testCacheRememberReturnsCachedValueOnSubsequentAccess(): void
    {
        $called = 0;
        $resolver = function () use (&$called) {
            $called++;
            return 'computed';
        };

        CacheRemember('test', 'key2', $resolver);
        CacheRemember('test', 'key2', $resolver);
        CacheRemember('test', 'key2', $resolver);

        $this->assertSame(1, $called);
    }

    public function testCacheRememberStoresDifferentValuesForDifferentKeys(): void
    {
        $a = CacheRemember('test', 'alpha', fn() => 'val-a');
        $b = CacheRemember('test', 'beta',  fn() => 'val-b');

        $this->assertSame('val-a', $a);
        $this->assertSame('val-b', $b);
    }

    public function testCacheRememberCachesNullValue(): void
    {
        $called = 0;
        $resolver = function () use (&$called) {
            $called++;
            return null;
        };

        $first  = CacheRemember('test', 'null-key', $resolver);
        $second = CacheRemember('test', 'null-key', $resolver);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $called);
    }

    // --- CacheForgetNamespace ---

    public function testCacheForgetNamespaceEvictsAllKeysInNamespace(): void
    {
        CacheRemember('fruits', 'apple',  fn() => 1);
        CacheRemember('fruits', 'banana', fn() => 2);
        CacheRemember('other', 'item',    fn() => 3);

        CacheForgetNamespace('fruits');

        $appleCallCount = 0;
        CacheRemember('fruits', 'apple', function () use (&$appleCallCount) {
            $appleCallCount++;
            return 99;
        });

        $this->assertSame(1, $appleCallCount, 'fruits:apple should have been evicted');

        $otherCallCount = 0;
        CacheRemember('other', 'item', function () use (&$otherCallCount) {
            $otherCallCount++;
            return 99;
        });
        $this->assertSame(0, $otherCallCount, 'other:item should still be cached');
    }

    public function testCacheForgetNamespaceIsNoOpForUnknownNamespace(): void
    {
        CacheForgetNamespace('nonexistent_namespace_xyz');
        // No exception — just passes
        $this->assertTrue(true);
    }
}
