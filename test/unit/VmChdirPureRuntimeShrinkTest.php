<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmChdirNative;
use PHPCompiler\ext\standard\VmChdirPure;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\ext\standard\VmGetcwdPure;
use PHPUnit\Framework\TestCase;

/** VmChdirPure / VmGetcwdPure — chdir/getcwd without libc FFI (#8955). */
final class VmChdirPureRuntimeShrinkTest extends TestCase
{
    public function testVmChdirNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChdirNative.php');
        $this->assertStringContainsString('VmChdirPure::', $source);
        $this->assertStringContainsString('VmChdirPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testVmGetcwdNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGetcwdNative.php');
        $this->assertStringContainsString('VmGetcwdPure::', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testVmChdirPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChdirPure.php');
        $this->assertStringContainsString('chdir', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testVmGetcwdPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGetcwdPure.php');
        $this->assertStringContainsString('getcwd', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testChdirGetcwdRoundTripWhenFfiDisabled(): void
    {
        if (!VmChdirPure::available() || !VmGetcwdPure::available()) {
            $this->markTestSkipped('host chdir/getcwd unavailable');
        }

        $orig = VmGetcwdNative::resolve();
        if (false === $orig) {
            $this->markTestSkipped('cannot resolve starting cwd');
        }

        $target = sys_get_temp_dir().'/phpc_chdir_pure_'.getmypid();
        if (!is_dir($target) && !@mkdir($target, 0700)) {
            $this->markTestSkipped('cannot create temp dir');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmChdirNative::available());
            $this->assertTrue(VmChdirNative::chdir($target));
            $here = VmGetcwdNative::resolve();
            $this->assertIsString($here);
            $this->assertSame(realpath($target), $here);
            $this->assertSame($here, VmFs::getcwd());
            $this->assertTrue(VmChdirNative::chdir($orig));
            $this->assertSame($orig, VmFs::getcwd());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        @rmdir($target);
    }
}
