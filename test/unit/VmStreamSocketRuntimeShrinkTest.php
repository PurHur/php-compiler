<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmStreamSocketNative;
use PHPUnit\Framework\TestCase;

/** stream_socket_client must not delegate to host Zend stream wrappers (#8097). */
final class VmStreamSocketRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketClientBuiltinDoesNotCallHostZend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/stream_socket_client.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_client\\s*\\(/', $source);
        $this->assertStringContainsString('VmStreamSocketNative::client', $source);
    }

    public function testVmStreamSocketNativeUsesLibcSocketNotHostStreams(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketNative.php');
        $this->assertStringContainsString('$ffi->socket', $source);
        $this->assertStringContainsString('$ffi->connect', $source);
        $this->assertStringContainsString('php://fd/', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\stream_socket_client\\s*\\(/', $source);
    }

    public function testClosedDiscardPortReturnsFalseWithErrno(): void
    {
        if (!VmStreamSocketNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        [$stream, $errno, $errstr] = VmStreamSocketNative::client('tcp://127.0.0.1:9', 1.0, 4);
        $this->assertFalse($stream);
        $this->assertIsInt($errno);
        $this->assertIsString($errstr);
    }
}
