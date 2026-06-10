<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStatCache;
use PHPUnit\Framework\TestCase;

/**
 * VmStatCache per-path invalidation (#6265, #7844).
 */
final class VmStatCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        VmStatCache::reset();
        parent::tearDown();
    }

    public function testVmStatCacheUsesNativeStatWithoutHostDelegation(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatCache.php');
        $this->assertStringContainsString('VmStatNative::stat', $cache);
        $this->assertStringContainsString('VmStatNative::lstat', $cache);
        $this->assertStringNotContainsString('syncHostClearstatcache', $cache);

        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatNative.php');
        $this->assertStringContainsString('int stat(const char *pathname', $native);
        $this->assertStringContainsString('$ffi->stat($path', $native);
    }

    public function testClearstatcacheBuiltinDelegatesToVmStatCache(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/clearstatcache_.php');
        $this->assertStringNotContainsString('\\clearstatcache(', $source);
        $this->assertStringContainsString('VmStatCache::clear', $source);
    }

    public function testNativeStatMatchesHostStatLayout(): void
    {
        if (!\PHPCompiler\ext\standard\VmStatNative::available()) {
            $this->markTestSkipped('FFI stat unavailable');
        }
        $path = __FILE__;
        $native = VmStatCache::stat($path);
        VmStatCache::reset();
        $zend = @\stat($path);
        $this->assertIsArray($native);
        $this->assertIsArray($zend);
        foreach (['dev', 'ino', 'mode', 'size', 'mtime'] as $key) {
            $this->assertSame((int) $zend[$key], (int) $native[$key], $key);
        }
    }

    public function testClearAllDropsEntries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_vm_statcache_all_');
        $this->assertNotFalse($path);
        try {
            VmStatCache::reset();
            $this->assertIsArray(VmStatCache::stat($path));
            VmStatCache::clear();
            chmod($path, 0600);
            $fresh = VmStatCache::stat($path);
            $this->assertIsArray($fresh);
            $this->assertSame(0600, $fresh['mode'] & 0777);
        } finally {
            @unlink($path);
        }
    }

    public function testNegativeCacheInvalidatesAfterTouch(): void
    {
        $path = sys_get_temp_dir().'/phpc_vm_statcache_touch_'.getmypid();
        @unlink($path);
        try {
            VmStatCache::reset();
            $this->assertFalse(VmStatCache::stat($path));
            $this->assertFalse(VmStatCache::stat($path));
            $this->assertTrue(touch($path));
            VmStatCache::invalidatePath($path);
            $fresh = VmStatCache::stat($path);
            $this->assertIsArray($fresh);
            $this->assertGreaterThan(0, $fresh['atime'] ?? 0);
        } finally {
            @unlink($path);
        }
    }
}
