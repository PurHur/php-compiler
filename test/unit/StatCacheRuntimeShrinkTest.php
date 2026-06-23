<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StatCacheRuntime must route through StatCacheJitHelper PHP, not LLVM hashtable globals (#9244). */
final class StatCacheRuntimeShrinkTest extends TestCase
{
    public function testStatCacheRuntimeUsesStatCacheJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatCacheRuntime.php');
        $this->assertStringContainsString('StatCacheJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("addGlobal(\$htPtr, 'phpc_stat_mode_cache')", $source);
        $this->assertStringNotContainsString('emitModeCached', $source);
        $this->assertStringNotContainsString('emitClear', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
    }

    public function testStatCacheJitHelperDelegatesToVmStatCache(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatCacheJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmStatCache::lstat', $source);
        $this->assertStringContainsString('VmStatCache::clear', $source);
        $this->assertStringContainsString('clearAll', $source);
    }

    public function testJitClearstatcacheUsesStatCacheRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClearstatcache.php');
        $this->assertStringContainsString('StatCacheRuntime::FN_CLEAR', $source);
        $this->assertStringContainsString('StatCacheRuntime::ensureLinked', $source);
    }
}
