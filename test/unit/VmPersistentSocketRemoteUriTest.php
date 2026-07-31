<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmPersistentSocket;
use PHPUnit\Framework\TestCase;

/**
 * fsockopen/pfsockopen URI merge must preserve existing schemes (#25779).
 *
 * php-src: ext/standard/fsock.c — php_fsockopen_format_host_port
 */
final class VmPersistentSocketRemoteUriTest extends TestCase
{
    public function testBareHostGetsTcpPrefixAndPort(): void
    {
        $this->assertSame('tcp://127.0.0.1:9', VmPersistentSocket::remoteUri('127.0.0.1', 9));
        $this->assertSame('tcp://127.0.0.1', VmPersistentSocket::remoteUri('127.0.0.1', -1));
    }

    public function testExistingSchemeKeepsSchemeAndAppendsPort(): void
    {
        $this->assertSame('udp://127.0.0.1:9', VmPersistentSocket::remoteUri('udp://127.0.0.1', 9));
        $this->assertSame('tcp://127.0.0.1:9', VmPersistentSocket::remoteUri('tcp://127.0.0.1', 9));
        $this->assertSame(
            'unix:///tmp/no.sock',
            VmPersistentSocket::remoteUri('unix:///tmp/no.sock', -1)
        );
        $this->assertSame(
            'unix:///tmp/no.sock:9',
            VmPersistentSocket::remoteUri('unix:///tmp/no.sock', 9)
        );
    }

    public function testPortZeroDoesNotAppendLikePhpSrc(): void
    {
        // php-src uses port > 0, so port 0 leaves the host unchanged (plus tcp:// for bare hosts)
        $this->assertSame('tcp://127.0.0.1', VmPersistentSocket::remoteUri('127.0.0.1', 0));
        $this->assertSame('udp://127.0.0.1', VmPersistentSocket::remoteUri('udp://127.0.0.1', 0));
    }
}
