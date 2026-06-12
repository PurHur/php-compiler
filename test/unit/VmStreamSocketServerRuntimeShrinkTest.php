<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPUnit\Framework\TestCase;

/** stream_socket_server must not delegate to host Zend stream wrappers (#4993). */
final class VmStreamSocketServerRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketServerBuiltinDoesNotCallHostZend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_socket_server.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_server\\s*\\(/', $source);
        $this->assertStringContainsString('VmStreamSocketNative::server', $source);
    }

    public function testVmStreamSocketNativeServerUsesLibcBindListen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('public static function server', $source);
        $this->assertStringContainsString('$ffi->bind', $source);
        $this->assertStringContainsString('$ffi->listen', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_server\\s*\\(/', $source);
    }

    public function testLoopbackBindReturnsStreamResource(): void
    {
        if (!VmStreamSocketNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        [$stream, $errno, $errstr] = VmStreamSocketNative::server(
            'tcp://127.0.0.1:0',
            VmStreamSocketNative::STREAM_SERVER_BIND | VmStreamSocketNative::STREAM_SERVER_LISTEN
        );
        $this->assertIsResource($stream);
        $this->assertSame(0, $errno);
        $this->assertSame('', $errstr);
        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }
}
