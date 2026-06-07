<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStatCache;
use PHPUnit\Framework\TestCase;

/**
 * VmStatCache per-path invalidation (#6265).
 */
final class VmStatCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        VmStatCache::reset();
        parent::tearDown();
    }

    public function testClearstatcacheBuiltinDelegatesToVmStatCache(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/clearstatcache_.php');
        $this->assertStringNotContainsString('\\clearstatcache(', $source);
        $this->assertStringContainsString('VmStatCache::clear', $source);
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
}
