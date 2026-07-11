<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmFsOpenPure;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPCompiler\ext\standard\VmFsWritePure;
use PHPUnit\Framework\TestCase;

/** VmFsOpenPure / VmFsWritePure — fopen and file_put_contents without libc FFI (#8950). */
final class VmFsOpenPureRuntimeShrinkTest extends TestCase
{
    public function testVmFsOpenNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsOpenNative.php');
        $this->assertStringContainsString('VmFsOpenPure::open', $source);
        $this->assertStringContainsString('VmFsOpenPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testVmFsWriteNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsWriteNative.php');
        $this->assertStringContainsString('VmFsWritePure::write', $source);
        $this->assertStringContainsString('VmFsWritePure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testVmFsOpenPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsOpenPure.php');
        $this->assertStringContainsString('fopen', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int open(const char', $source);
    }

    public function testVmFsWritePureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsWritePure.php');
        $this->assertStringContainsString('file_put_contents', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('ssize_t write(int fd', $source);
    }

    public function testFopenReadWriteRoundTripWhenFfiDisabled(): void
    {
        if (!VmFsOpenPure::available() || !VmFsWritePure::available()) {
            $this->markTestSkipped('host fopen/file_put_contents unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fopen_pure_');
        $this->assertNotFalse($path);
        @unlink($path);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsOpenNative::available());
            $this->assertTrue(VmFsWriteNative::available());

            $writeHandle = VmFs::fopen($path, 'wb');
            $this->assertNotFalse($writeHandle);
            $this->assertSame(2, VmFs::fwrite($writeHandle, 'xy'));
            VmFs::fclose($writeHandle);

            $readHandle = VmFs::fopen($path, 'rb');
            $this->assertNotFalse($readHandle);
            $this->assertSame('xy', VmFs::fread($readHandle, 10));
            VmFs::fclose($readHandle);

            $this->assertSame(15, VmFs::filePutContents($path, 'via-vmfs-helper'));
            $this->assertSame('via-vmfs-helper', VmFs::fileGetContents($path));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        @unlink($path);
    }

    public function testFilePutContentsAppendAndLockExWhenFfiDisabled(): void
    {
        if (!VmFsWritePure::available()) {
            $this->markTestSkipped('host file_put_contents unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fpc_pure_');
        $this->assertNotFalse($path);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame(2, VmFs::filePutContents($path, 'ab', \LOCK_EX));
            $this->assertSame(2, VmFs::filePutContents($path, 'cd', \FILE_APPEND | \LOCK_EX));
            $this->assertSame('abcd', VmFs::fileGetContents($path));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        @unlink($path);
    }
}
