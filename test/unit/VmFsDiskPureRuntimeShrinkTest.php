<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDiskNative;
use PHPCompiler\ext\standard\VmFsDiskPure;
use PHPUnit\Framework\TestCase;

/** VmFsDiskPure — disk_free_space()/disk_total_space() without libc statvfs FFI (#8989). */
final class VmFsDiskPureRuntimeShrinkTest extends TestCase
{
    public function testVmFsDiskNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDiskNative.php');
        $this->assertStringContainsString('VmFsDiskPure::diskFreeSpace', $source);
        $this->assertStringContainsString('VmFsDiskPure::diskTotalSpace', $source);
        $this->assertStringContainsString('VmFsDiskPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->statvfs', $source);
    }

    public function testVmFsDiskPureDoesNotUseStatvfsFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDiskPure.php');
        $this->assertStringContainsString('\\disk_free_space(', $source);
        $this->assertStringContainsString('\\disk_total_space(', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->statvfs/', $source);
    }

    public function testVmFsRoutesThroughVmFsDiskNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsDiskNative::diskFreeSpace', $source);
        $this->assertStringContainsString('VmFsDiskNative::diskTotalSpace', $source);
        $this->assertStringNotContainsString('ffiEnabledForDisk', $source);
    }

    public function testDiskSpaceNoFfiReturnsPositiveFloat(): void
    {
        if (!VmFsDiskPure::available()) {
            $this->markTestSkipped('host disk_*() unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsDiskNative::available());
            $path = sys_get_temp_dir();
            $free = VmFsDiskNative::diskFreeSpace($path);
            $total = VmFsDiskNative::diskTotalSpace($path);
            $this->assertIsFloat($free);
            $this->assertIsFloat($total);
            $this->assertGreaterThan(0.0, $free);
            $this->assertGreaterThan(0.0, $total);

            $vmFree = VmFs::diskFreeSpace($path);
            $vmTotal = VmFs::diskTotalSpace($path);
            $this->assertSame($free, $vmFree);
            $this->assertSame($total, $vmTotal);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testDiskPureReturnsFalseForMissingPath(): void
    {
        if (!VmFsDiskPure::available()) {
            $this->markTestSkipped('host disk_*() unavailable');
        }
        $this->assertFalse(VmFsDiskPure::diskFreeSpace('/no/such/phpc-disk-pure-'.bin2hex(random_bytes(4))));
    }
}
