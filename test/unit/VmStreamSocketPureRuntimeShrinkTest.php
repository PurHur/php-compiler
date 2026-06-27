<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPCompiler\ext\standard\VmStreamSocketPure;
use PHPUnit\Framework\TestCase;

/** VmStreamSocketPure — TCP/UDP client without libc socket FFI (#8953, #12858). */
final class VmStreamSocketPureRuntimeShrinkTest extends TestCase
{
    public function testVmStreamSocketNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('VmStreamSocketPure::client', $source);
        $this->assertStringContainsString('VmStreamSocketPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->socket', $source);
    }

    public function testVmStreamSocketPureUsesHostStreamSocketClientNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketPure.php');
        $this->assertStringContainsString('stream_socket_client', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int socket(int domain', $source);
    }

    public function testStreamSocketClientDiscardPortWhenNativeAvailable(): void
    {
        if (!VmStreamSocketPure::available()) {
            $this->markTestSkipped('host stream_socket_client unavailable');
        }

        $this->assertTrue(VmStreamSocketNative::available());

        [$handle, $outErrno, $outErrstr, $socketFd] = VmStreamSocketNative::client(
            'tcp://127.0.0.1:9',
            1.0,
            \STREAM_CLIENT_CONNECT
        );

        $this->assertFalse($handle);
        $this->assertNotSame(0, $outErrno);
        $this->assertNotSame('', $outErrstr);
        $this->assertNull($socketFd);
    }
}
