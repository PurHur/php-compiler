<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MemoryJitHelper;
use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\VM\MemoryAccounting;
use PHPUnit\Framework\TestCase;

/**
 * MemoryRuntime routes through MemoryJitHelper PHP via JitVmHelperLink (#9377 / #24058).
 */
final class MemoryRuntimeShrinkTest extends TestCase
{
    public function testMemoryRuntimeRoutesThroughMemoryJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MemoryRuntime.php');
        $this->assertStringContainsString('MemoryJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('emitReadRssBytes', $source);
        $this->assertStringNotContainsString('/proc/self/statm', $source);
        $this->assertStringNotContainsString('GLOBAL_PEAK_EMALLOC', $source);
        $this->assertStringContainsString('useThinStandaloneUsageFloor', $source);
        $this->assertStringContainsString('THIN_AOT_USAGE_FLOOR', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testMemoryJitHelperDelegatesToVmMemoryAndAccounting(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MemoryJitHelper.php');
        $this->assertStringContainsString('VmMemory::getUsage', $source);
        $this->assertStringContainsString('MemoryAccounting::usageAfterPeakQuery', $source);
        $this->assertStringContainsString('VmGcStatus::memCaches', $source);
    }

    public function testMemoryJitHelperGcMemCachesReturnsInt(): void
    {
        MemoryAccounting::beginRequest();
        $expected = MemoryAccounting::initialMmCache();
        $this->assertSame($expected, MemoryJitHelper::gcMemCaches());
        $this->assertSame(0, MemoryJitHelper::gcMemCaches());
    }

    public function testJitMemoryUsesMemoryRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMemory.php');
        $this->assertStringContainsString('MemoryRuntime::getUsageValue', $source);
        $this->assertStringNotContainsString('readRssBytes', $source);
    }

    public function testNoteAllocAbiNameUnchanged(): void
    {
        $this->assertSame('__phpc_memory_note_alloc', MemoryRuntime::NOTE_ALLOC);
    }
}
