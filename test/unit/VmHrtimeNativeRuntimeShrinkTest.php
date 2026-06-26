<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmClockGettime;
use PHPCompiler\ext\standard\VmHrtimeNative;
use PHPUnit\Framework\TestCase;

/** VmHrtimeNative — /proc/uptime + microtime without libc clock_gettime FFI (#12144, #12225, #12236). */
final class VmHrtimeNativeRuntimeShrinkTest extends TestCase
{
    public function testVmHrtimeNativeUsesPurePathsWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHrtimeNative.php');
        $this->assertStringContainsString('/proc/uptime', $source);
        $this->assertStringContainsString('microtime', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->clock_gettime', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testReadMonotonicWorksWithFfiDisabled(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            [$sec, $nsec] = VmHrtimeNative::readMonotonic();
            $this->assertGreaterThan(0, $sec + $nsec);
            $realtime = VmHrtimeNative::readClock(VmHrtimeNative::CLOCK_REALTIME);
            $this->assertIsArray($realtime);
            $this->assertGreaterThan(0, $realtime[0]);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testClockGettimeMonotonicWithFfiDisabled(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux only');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $result = VmClockGettime::clockGettime(VmHrtimeNative::CLOCK_MONOTONIC);
            $this->assertNotFalse($result);
            $secVar = $result->find('sec');
            $this->assertNotNull($secVar);
            $this->assertGreaterThan(0, $secVar->toInt());
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
