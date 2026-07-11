<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmChrootNative;
use PHPCompiler\ext\standard\VmChrootPure;
use PHPUnit\Framework\TestCase;

/** VmChrootPure / VmChrootNative — chroot without libc FFI (#12192). */
final class VmChrootNativeRuntimeShrinkTest extends TestCase
{
    public function testVmChrootNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChrootNative.php');
        $this->assertStringContainsString('VmChrootPure::', $source);
        $this->assertStringContainsString('VmChrootPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testVmChrootPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChrootPure.php');
        $this->assertStringContainsString('chroot', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testChrootReturnsFalseWhenFfiDisabled(): void
    {
        if (!VmChrootPure::available()) {
            $this->markTestSkipped('host chroot unavailable');
        }
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmChrootNative::available());
            $this->assertFalse(VmChrootNative::chroot('/no/such/phpc-chroot-pure-path'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testChrootDeniedForTypicalUnprivilegedProcess(): void
    {
        if (!VmChrootNative::available()) {
            $this->markTestSkipped('chroot unavailable');
        }
        $this->assertFalse(VmChrootNative::chroot('/no/such/phpc-chroot-pure-path'));
    }
}
