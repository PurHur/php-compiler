<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPCompiler\ext\standard\VmStreamSocketPure;
use PHPUnit\Framework\TestCase;

/** stream_socket_client must not delegate to host Zend in builtin; Native → Pure SSOT (#8097, #12858). */
final class VmStreamSocketRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketClientBuiltinDoesNotCallHostZend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_socket_client.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_client\\s*\\(/', $source);
        $this->assertStringContainsString('VmStreamSocketNative::client', $source);
    }

    public function testVmStreamSocketNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('VmStreamSocketPure::client', $source);
        $this->assertStringContainsString('VmStreamSocketPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->socket', $source);
        $this->assertStringNotContainsString('$ffi->connect', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_client\\s*\\(/', $source);
    }

    public function testVmStreamSocketPureUsesHostStreamClientNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketPure.php');
        $this->assertStringContainsString('stream_socket_client', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int socket(int domain', $source);
    }

    public function testClosedDiscardPortReturnsFalseWithErrno(): void
    {
        if (!VmStreamSocketPure::available()) {
            $this->markTestSkipped('host stream_socket_client unavailable');
        }

        [$stream, $errno, $errstr, $socketFd] = VmStreamSocketNative::client('tcp://127.0.0.1:9', 1.0, 4);
        $this->assertFalse($stream);
        $this->assertNotSame(0, $errno);
        $this->assertNotSame('', $errstr);
        $this->assertNull($socketFd);
    }
}
