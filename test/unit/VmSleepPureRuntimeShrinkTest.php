<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmSleep;
use PHPCompiler\ext\standard\VmSleepNative;
use PHPCompiler\ext\standard\VmSleepPure;
use PHPUnit\Framework\TestCase;

/** VmSleepPure — sleep/usleep without libc FFI (#8922, #8971). */
final class VmSleepPureRuntimeShrinkTest extends TestCase
{
    public function testVmSleepNativeDelegatesToPureOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSleepNative.php');
        $this->assertStringContainsString('VmSleepPure::sleep', $source);
        $this->assertStringContainsString('VmSleepPure::usleep', $source);
        $this->assertStringContainsString('VmSleepPure::timeNanosleep', $source);
        $this->assertStringContainsString('VmSleepPure::timeSleepUntil', $source);
        $this->assertStringNotContainsString('ffi()->sleep', $source);
        $this->assertStringNotContainsString('ffi()->usleep', $source);
        $this->assertStringNotContainsString('$ffi->nanosleep', $source);
        $this->assertStringNotContainsString('$ffi->gettimeofday', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testVmSleepPureUsesHrtimeNotHostSleep(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSleepPure.php');
        $this->assertStringContainsString('VmHrtimeNative::readMonotonic', $source);
        $this->assertStringContainsString('VmHrtime::hrtime', $source);
        $this->assertStringNotContainsString('\\sleep(', $source);
        $this->assertStringNotContainsString('\\usleep(', $source);
    }

    public function testSleepZeroReturnsZeroWithFfiEnabled(): void
    {
        $this->assertTrue(VmSleepNative::available());
        $this->assertSame(0, VmSleepPure::sleep(0));
        $this->assertSame(0, VmSleep::sleep(0));
        $this->assertSame(0, VmSleepNative::sleep(0));
    }

    public function testUsleepZeroCompletesWithFfiEnabled(): void
    {
        VmSleepPure::usleep(0);
        VmSleep::usleep(0);
        VmSleepNative::usleep(0);
        $this->assertTrue(true);
    }

    public function testSleepZeroReturnsZeroWithFfiDisabled(): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmSleepNative::available());
            $this->assertSame(0, VmSleepPure::sleep(0));
            $this->assertSame(0, VmSleep::sleep(0));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testUsleepZeroCompletesWithFfiDisabled(): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            VmSleepPure::usleep(0);
            VmSleep::usleep(0);
            $this->assertTrue(true);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testNegativeSleepThrowsValueErrorInPurePath(): void
    {
        $this->expectException(\ValueError::class);
        VmSleepPure::sleep(-1);
    }

    public function testNegativeUsleepThrowsValueErrorInPurePath(): void
    {
        $this->expectException(\ValueError::class);
        VmSleepPure::usleep(-1);
    }
}
