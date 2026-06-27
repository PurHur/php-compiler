<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPCompiler\ext\standard\VmStreamSocketPure;
use PHPUnit\Framework\TestCase;

/** stream_socket_server must not delegate to host Zend in builtin; Native → Pure SSOT (#4993, #12858). */
final class VmStreamSocketServerRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketServerBuiltinDoesNotCallHostZend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_socket_server.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_server\\s*\\(/', $source);
        $this->assertStringContainsString('VmStreamSocketNative::server', $source);
    }

    public function testVmStreamSocketNativeServerDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('VmStreamSocketPure::server', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->bind', $source);
        $this->assertStringNotContainsString('$ffi->listen', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_server\\s*\\(/', $source);
    }

    public function testLoopbackBindReturnsVmStreamHandle(): void
    {
        if (!VmStreamSocketPure::available()) {
            $this->markTestSkipped('host stream_socket_server unavailable');
        }

        [$stream, $errno, $errstr, $socketFd] = VmStreamSocketNative::server(
            'tcp://127.0.0.1:0',
            VmStreamSocketNative::STREAM_SERVER_BIND | VmStreamSocketNative::STREAM_SERVER_LISTEN
        );
        $this->assertIsInt($stream);
        $this->assertTrue(VmFs::isValidHandle($stream));
        $this->assertSame(0, $errno);
        $this->assertSame('', $errstr);
        $this->assertIsInt($socketFd);
        $this->assertGreaterThan(0, $socketFd);
        VmFs::fclose($stream);
    }
}
