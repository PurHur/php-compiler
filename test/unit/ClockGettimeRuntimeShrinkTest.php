<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StringClockGettimeRuntime: always JitVmHelperLink::ensureCompiled — no hand-rolled NestedJit putenv (#21270).
 */
final class ClockGettimeRuntimeShrinkTest extends TestCase
{
    public function testStringClockGettimeRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClockGettimeRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('ClockGettimeJitHelper', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testStringClockGettimeRoutesThroughRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClockGettime.php');
        $this->assertStringContainsString('StringClockGettimeRuntime::ensureLinked', $source);
    }

    public function testClockGettimeJitHelperDelegatesToVm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ClockGettimeJitHelper.php');
        $this->assertStringContainsString('VmHrtimeNative::readClock', $source);
        $this->assertStringContainsString('VmClockGettime::buildResult', $source);
    }
}
