<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Sleep JIT helpers route through VmSleepPure PHP, not nanosleep/gettimeofday LLVM (#9378). */
final class SleepJitRuntimeShrinkTest extends TestCase
{
    public function testSleepJitHelperDelegatesToVmSleepPure(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/SleepJitHelper.php');
        $this->assertStringContainsString('VmSleepPure::timeNanosleep', $source);
        $this->assertStringContainsString('VmSleepPure::timeSleepUntil', $source);
        $this->assertStringNotContainsString('lookupFunction(\'nanosleep\')', $source);
        $this->assertStringNotContainsString('lookupFunction(\'gettimeofday\')', $source);
    }

    public function testTimeSleepRuntimeRoutesThroughSleepJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TimeSleepRuntime.php');
        $this->assertStringContainsString('SleepJitHelper', $source);
        $this->assertStringNotContainsString('TimeSleepRuntimeLibcBridge', $source);
        $this->assertStringNotContainsString('nanosleepLoop', $source);
        $this->assertStringNotContainsString('ensureLibcTime', $source);
        $this->assertStringNotContainsString('lookupFunction(\'nanosleep\')', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/TimeSleepRuntimeLibcBridge.php');
    }

    public function testTimeNanosleepZeroSleepReturnsTrue(): void
    {
        $this->assertTrue(\PHPCompiler\ext\standard\SleepJitHelper::timeNanosleep(0, 0));
    }
}
