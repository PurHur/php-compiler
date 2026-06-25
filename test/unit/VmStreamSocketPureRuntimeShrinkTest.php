<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPCompiler\ext\standard\VmStreamSocketPure;
use PHPUnit\Framework\TestCase;

/** VmStreamSocketPure — TCP/UDP client without libc socket FFI (#8953). */
final class VmStreamSocketPureRuntimeShrinkTest extends TestCase
{
    public function testVmStreamSocketNativeDelegatesToPureWhenFfiDisabled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('VmStreamSocketPure::client', $source);
        $this->assertStringContainsString('VmStreamSocketPure::available()', $source);
        $this->assertStringContainsString('$ffi->socket', $source);
    }

    public function testVmStreamSocketPureUsesHostStreamSocketClientNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketPure.php');
        $this->assertStringContainsString('stream_socket_client', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int socket(int domain', $source);
    }

    public function testStreamSocketClientDiscardPortWhenFfiDisabled(): void
    {
        if (!VmStreamSocketPure::available()) {
            $this->markTestSkipped('host stream_socket_client unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmStreamSocketNative::available());

            [$handle, $outErrno, $outErrstr] = VmStreamSocketNative::client(
                'tcp://127.0.0.1:9',
                1.0,
                \STREAM_CLIENT_CONNECT
            );

            $this->assertFalse($handle);
            $this->assertNotSame(0, $outErrno);
            $this->assertNotSame('', $outErrstr);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
