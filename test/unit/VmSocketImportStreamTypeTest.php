<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\sockets\VmSocket;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** socket_import_stream() follows stream_type for php://stdio URIs (#19996). */
final class VmSocketImportStreamTypeTest extends TestCase
{
    public function testCanImportPhpStdinUriWhenHostResourceIsUnixSocket(): void
    {
        if (!\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('stream_socket_pair unavailable');
        }
        $pair = @\stream_socket_pair(\AF_UNIX, \SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (false === $pair) {
            $this->markTestSkipped('stream_socket_pair failed');
        }
        try {
            $handle = VmFs::adoptStreamResource($pair[0], 'php://stdin');
            $this->assertIsInt($handle);
            $this->assertTrue(VmSocket::canImportStreamHandle($handle));
        } finally {
            if (isset($handle) && \is_int($handle)) {
                VmFs::fclose($handle);
            }
            if (\is_resource($pair[1])) {
                \fclose($pair[1]);
            }
        }
    }
}
