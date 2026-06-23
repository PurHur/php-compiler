<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDiskNative;
use PHPCompiler\ext\standard\VmFsDiskPure;
use PHPUnit\Framework\TestCase;

/** VmFs disk space via VmFsDiskNative (statvfs FFI or VmFsDiskPure); not host disk_*() (#8989). */
final class VmFsDiskNativeTest extends TestCase
{
    public function testSourceUsesStatvfsPrimaryPath(): void
    {
        $vmFs = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsDiskNative::diskFreeSpace', $vmFs);
        $this->assertStringContainsString('VmFsDiskNative::diskTotalSpace', $vmFs);

        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDiskNative.php');
        $this->assertStringContainsString('int statvfs(const char *path', $native);
        $this->assertStringContainsString('$ffi->statvfs($path', $native);
        $this->assertStringContainsString('VmFsDiskPure::diskFreeSpace', $native);
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

    public function testVmFsDiskSpaceWithoutFfiUsesPurePath(): void
    {
        if (!VmFsDiskPure::available()) {
            $this->markTestSkipped('host disk_*() unavailable');
        }
        $path = sys_get_temp_dir();
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $free = VmFs::diskFreeSpace($path);
            $total = VmFs::diskTotalSpace($path);
            $this->assertIsFloat($free);
            $this->assertIsFloat($total);
            $this->assertGreaterThan(0.0, $free);
            $this->assertGreaterThan(0.0, $total);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
