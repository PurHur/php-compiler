<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsReadPure;
use PHPUnit\Framework\TestCase;

/** VmFsReadPure — file reads without libc open/read FFI (#8920). */
final class VmFsReadPureRuntimeShrinkTest extends TestCase
{
    public function testVmFsReadNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsReadNative.php');
        $this->assertStringContainsString('VmFsReadPure::readSlice', $source);
        $this->assertStringContainsString('VmFsReadPure::read', $source);
        $this->assertStringContainsString('VmFsReadPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int open(const char', $source);
    }

    public function testVmFsReadPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsReadPure.php');
        $this->assertStringContainsString('fopen', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int open(const char', $source);
    }

    public function testReadRoundTripViaPurePath(): void
    {
        if (!VmFsReadPure::available()) {
            $this->markTestSkipped('host fopen unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_read_pure_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'hello-pure-read');

        $this->assertSame('hello-pure-read', VmFsReadPure::read($path));
        $this->assertSame('pure', VmFsReadPure::readSlice($path, 6, 4));

        @unlink($path);
    }

    public function testReadNativeFallsBackToPureWhenFfiDisabled(): void
    {
        if (!VmFsReadPure::available()) {
            $this->markTestSkipped('host fopen unavailable');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_read_ffi_off_');
        $this->assertNotFalse($path);
        $payload = 'ffi-disabled-read-'.bin2hex(random_bytes(4));
        file_put_contents($path, $payload);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsReadNative::available());
            $this->assertSame($payload, VmFsReadNative::read($path));
            $this->assertSame($payload, VmFs::fileGetContents($path));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }

        @unlink($path);
    }

    public function testIssueReproReadsSelfWhenFfiDisabled(): void
    {
        if (!VmFsReadPure::available()) {
            $this->markTestSkipped('host fopen unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $source = VmFsReadNative::read(__FILE__);
            $this->assertIsString($source);
            $this->assertStringContainsString('VmFsReadPureRuntimeShrinkTest', $source);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
