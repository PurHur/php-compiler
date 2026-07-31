<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmFsOpenPure;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPCompiler\ext\standard\VmFsWritePure;
use PHPUnit\Framework\TestCase;

/** VmFsOpenPure / VmFsWritePure — fopen/fwrite without libc FFI (#8950, #20266). */
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
        $this->assertStringContainsString('VmFopenMode::isValid', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int open(const char', $source);
    }

    public function testEmptyAndJunkModesFailWithoutAllocatingHandle(): void
    {
        if (!VmFsOpenPure::available()) {
            $this->markTestSkipped('host fopen unavailable');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc_fopen_mode_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'x');
        try {
            $this->assertFalse(VmFsOpenPure::open($path, ''));
            $this->assertSame(
                "`' is not a valid mode for fopen",
                \PHPCompiler\ext\standard\VmFopenMode::lastOpenFailureDetail()
            );
            $this->assertFalse(VmFsOpenPure::open($path, 'q'));
            $this->assertSame(
                "`q' is not a valid mode for fopen",
                \PHPCompiler\ext\standard\VmFopenMode::lastOpenFailureDetail()
            );
            $ok = VmFsOpenPure::open($path, 'r');
            $this->assertNotFalse($ok);
            $this->assertNull(\PHPCompiler\ext\standard\VmFopenMode::lastOpenFailureDetail());
            VmFs::fclose((int) $ok);
        } finally {
            @unlink($path);
        }
    }

    public function testVmFsWritePureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsWritePure.php');
        $this->assertStringContainsString('fopen', $source);
        $this->assertStringContainsString('fwrite', $source);
        $this->assertStringNotContainsString('\\file_put_contents', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('ssize_t write(int fd', $source);
    }

    public function testFopenReadWriteRoundTripWhenFfiDisabled(): void
    {
        if (!VmFsOpenPure::available() || !VmFsWritePure::available()) {
            $this->markTestSkipped('host fopen/fwrite unavailable');
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
            $this->markTestSkipped('host fopen/fwrite unavailable');
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
