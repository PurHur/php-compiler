<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\VmPosixTimesPure;
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

    public function testVmPosixTimesUsesPureProcPathNotLibcTimes(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmPosixTimesPure::times()', $vmPosix);
        $this->assertStringNotContainsString('times(struct tms', $vmPosix);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixTimesPure.php');
        $this->assertStringContainsString('/proc/self/stat', $pure);
        $this->assertStringContainsString('VmProcClockTicksPure::clockTicksPerSecond', $pure);
        $this->assertStringContainsString('PosixLibcThinAbi::systemClockTicks', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
    }

    public function testPosixTimesTicksWithinOrderOfMagnitudeOfZendOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\function_exists('posix_times')) {
            $this->markTestSkipped('Linux libc posix_times only');
        }

        $zend = \posix_times();
        $got = VmPosixTimesPure::times();
        $this->assertIsArray($zend);
        $this->assertNotNull($got);

        $zendTicks = (int) ($zend['ticks'] ?? 0);
        $vmTicks = (int) ($got['ticks'] ?? 0);
        $this->assertGreaterThan(0, $zendTicks);
        $this->assertGreaterThan(0, $vmTicks);

        $ratio = \max($zendTicks, $vmTicks) / \max(1, \min($zendTicks, $vmTicks));
        $this->assertLessThanOrEqual(10.0, $ratio, "zend={$zendTicks} vm={$vmTicks}");
    }

    public function testPosixTimesPureReturnsPositiveTicksOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux /proc only');
        }

        $got = VmPosixTimesPure::times();
        $this->assertNotNull($got);
        $this->assertGreaterThan(0, $got['ticks']);
        foreach (['utime', 'stime', 'cutime', 'cstime'] as $key) {
            $this->assertGreaterThanOrEqual(0, $got[$key]);
        }
    }
}
