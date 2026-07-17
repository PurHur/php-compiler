<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** socket_import_stream PHP-native import without host ext/sockets delegation (#6203, #8202). */
final class VmSocketImportRuntimeShrinkTest extends TestCase
{
    public function testImportStreamDoesNotDelegateToHostBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_import_stream.php');
        $this->assertStringContainsString('VmSocket::importStreamHandle', $source);
        $this->assertStringNotContainsString('@\\socket_import_stream($', $source);
        $this->assertStringNotContainsString("function_exists('socket_import_stream')", $source);
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $source);
    }

    public function testVmSocketDoesNotDelegateStreamMetaToHost(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/VmSocket.php');
        $this->assertStringContainsString('importStreamHandle', $source);
        $this->assertStringContainsString('stdioInheritedStreamType', $source);
        $this->assertStringNotContainsString('isStdioUri($uri)) {
            return false', $source);
        $this->assertStringContainsString('VmFs::socketFdForHandle', $source);
        $this->assertStringNotContainsString('stream_get_meta_data(', $source);
        $this->assertStringNotContainsString('stream_socket_get_name(', $source);
        $this->assertStringNotContainsString('socket_getsockname(', $source);
    }

    public function testModuleAlwaysRegistersSocketImportStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/Module.php');
        $this->assertStringContainsString('new socket_import_stream()', $source);
        $this->assertStringNotContainsString("function_exists('socket_import_stream')", $source);
    }

    public function testAtmarkUsesObjectFdPathForImportedStreams(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/VmSockets.php');
        $this->assertStringContainsString('atmarkForObject', $source);
        $this->assertStringContainsString('VmSocket::fdForObject', $source);
    }
}
