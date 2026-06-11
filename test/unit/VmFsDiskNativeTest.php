<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDiskNative;
use PHPUnit\Framework\TestCase;

/** VmFs disk space via libc statvfs FFI, not host disk_*() (#3758). */
final class VmFsDiskNativeTest extends TestCase
{
    public function testSourceUsesStatvfsPrimaryPath(): void
    {
        $vmFs = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsDiskNative::diskFreeSpace', $vmFs);
        $this->assertStringContainsString('VmFsDiskNative::diskTotalSpace', $vmFs);
        $this->assertStringContainsString('ffiEnabledForDisk', $vmFs);

        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDiskNative.php');
        $this->assertStringContainsString('int statvfs(const char *path', $native);
        $this->assertStringContainsString('$ffi->statvfs($path', $native);
        $this->assertDoesNotMatchRegularExpression('/\\\\disk_free_space\\s*\\(/', $native);
    }

    public function testNativeDiskSpaceMatchesHostOnLinux(): void
    {
        if (!VmFsDiskNative::available()) {
            $this->markTestSkipped('FFI statvfs unavailable');
        }
        $path = sys_get_temp_dir();
        $nativeFree = VmFsDiskNative::diskFreeSpace($path);
        $nativeTotal = VmFsDiskNative::diskTotalSpace($path);
        $this->assertIsFloat($nativeFree);
        $this->assertIsFloat($nativeTotal);
        $this->assertGreaterThan(0.0, $nativeFree);
        $this->assertGreaterThan(0.0, $nativeTotal);
        if (\function_exists('disk_free_space') && \function_exists('disk_total_space')) {
            $hostFree = (float) disk_free_space($path);
            $hostTotal = (float) disk_total_space($path);
            $this->assertEqualsWithDelta($hostFree, $nativeFree, max(1.0, $hostFree * 0.001));
            $this->assertEqualsWithDelta($hostTotal, $nativeTotal, max(1.0, $hostTotal * 0.001));
        }
    }

    public function testVmFsDiskSpaceWithoutFfiReturnsFalse(): void
    {
        $path = sys_get_temp_dir();
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmFs::diskFreeSpace($path));
            $this->assertFalse(VmFs::diskTotalSpace($path));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
