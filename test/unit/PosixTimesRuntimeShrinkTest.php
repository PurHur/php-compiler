<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** posix_times JIT routes through PosixTimesJitHelper PHP not JIT stub (#9218). */
final class PosixTimesRuntimeShrinkTest extends TestCase
{
    public function testPosixTimesCallUsesJitPosixTimes(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_times.php');
        $this->assertStringContainsString('JitPosixTimes::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testPosixSetsidCallUsesJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setsid.php');
        $this->assertStringContainsString('JitPosix::setsid', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testPosixTimesJitHelperDelegatesToVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixTimesJitHelper.php');
        $this->assertStringContainsString('VmPosix::times()', $source);
        $this->assertStringContainsString('VmPosix::timesToHashTable', $source);
    }

    public function testPosixTimesRuntimeIsThinBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixTimesRuntime.php');
        $this->assertStringContainsString('PosixTimesJitHelper::resolve', $source);
        $this->assertStringContainsString('__compiler_posix_times', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }
}
