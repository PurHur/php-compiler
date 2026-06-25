<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPCompiler\ext\standard\VmFsDirPure;
use PHPUnit\Framework\TestCase;

/** VmFsDirPure — mkdir/chmod/rmdir without libc FFI (#8991). */
final class VmFsDirPureRuntimeShrinkTest extends TestCase
{
    public function testVmFsDirNativeDelegatesToPureWhenFfiDisabled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDirNative.php');
        $this->assertStringContainsString('VmFsDirPure::', $source);
        $this->assertStringContainsString('VmFsDirPure::available()', $source);
    }

    public function testVmFsDirPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDirPure.php');
        $this->assertStringContainsString('mkdir', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int mkdir(const char', $source);
    }

    public function testMkdirChmodRmdirRoundTripViaPurePath(): void
    {
        if (!VmFsDirPure::available()) {
            $this->markTestSkipped('host mkdir unavailable');
        }

        $dir = sys_get_temp_dir().'/phpc_fsdir_pure_'.getmypid();
        @rmdir($dir);

        $this->assertTrue(VmFsDirPure::mkdir($dir, 0700, false));
        $this->assertTrue(is_dir($dir));
        $this->assertTrue(VmFsDirPure::chmod($dir, 0755));
        $this->assertTrue(VmFsDirPure::rmdir($dir));
        $this->assertFalse(is_dir($dir));
    }

    public function testMkdirChmodRmdirRoundTripWhenFfiDisabled(): void
    {
        if (!VmFsDirPure::available()) {
            $this->markTestSkipped('host mkdir unavailable');
        }

        $dir = sys_get_temp_dir().'/phpc_fsdir_ffi_off_'.getmypid();
        @rmdir($dir);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsDirNative::available());
            $this->assertTrue(VmFsDirNative::mkdir($dir, 0700, false));
            $this->assertTrue(VmFsDirNative::chmod($dir, 0755));
            $this->assertTrue(VmFs::chmod($dir, 0755));
            $this->assertTrue(VmFsDirNative::rmdir($dir));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        $this->assertFalse(is_dir($dir));
    }

    public function testRecursiveMkdirWhenFfiDisabled(): void
    {
        if (!VmFsDirPure::available()) {
            $this->markTestSkipped('host mkdir unavailable');
        }

        $base = sys_get_temp_dir().'/phpc_fsdir_rec_'.getmypid();
        $dir = $base.'/a/b';
        self::rmdirRecursive($base);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsDirNative::mkdir($dir, 0700, true));
            $this->assertTrue(is_dir($dir));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        self::rmdirRecursive($base);
    }

    private static function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_dir($child)) {
                self::rmdirRecursive($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
