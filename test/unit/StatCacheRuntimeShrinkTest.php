<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StatCacheRuntime routes through StatCacheJitHelper via JitVmHelperLink, not hand-rolled NestedJIT (#9244, #25882). */
final class StatCacheRuntimeShrinkTest extends TestCase
{
    public function testStatCacheRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatCacheRuntime.php');
        $this->assertStringContainsString('StatCacheJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("addGlobal(\$htPtr, 'phpc_stat_mode_cache')", $source);
        $this->assertStringNotContainsString('emitModeCached', $source);
        $this->assertStringNotContainsString('emitClear', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertLessThan(250, \substr_count($source, "\n") + 1);
    }

    public function testStatCacheJitHelperDelegatesToVmStatCache(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/StatCacheJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmStatCache::lstat', $source);
        $this->assertStringContainsString('VmStatCache::clear', $source);
        $this->assertStringContainsString('clearAll', $source);
    }

    public function testJitClearstatcacheUsesStatCacheRuntimeBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitClearstatcache.php');
        $this->assertStringContainsString('StatCacheRuntime::FN_CLEAR', $source);
        $this->assertStringContainsString('StatCacheRuntime::ensureLinked', $source);
    }
}
