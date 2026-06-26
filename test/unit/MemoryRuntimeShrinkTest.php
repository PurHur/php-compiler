<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MemoryJitHelper;
use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPCompiler\VM\MemoryAccounting;
use PHPUnit\Framework\TestCase;

/** MemoryRuntime routes through MemoryJitHelper PHP not RSS/statm LLVM (#9377). */
final class MemoryRuntimeShrinkTest extends TestCase
{
    public function testMemoryRuntimeRoutesThroughMemoryJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MemoryRuntime.php');
        $this->assertStringContainsString('MemoryJitHelper', $source);
        $this->assertStringNotContainsString('emitReadRssBytes', $source);
        $this->assertStringNotContainsString('/proc/self/statm', $source);
        $this->assertStringNotContainsString('GLOBAL_PEAK_EMALLOC', $source);
        $this->assertLessThan(300, \substr_count($source, "\n") + 1);
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
        $this->assertSame(61440, MemoryJitHelper::gcMemCaches());
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
