<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmStreamSocketPairNative;

/** VM FFI socketpair helper for stream_socket_pair() (#3437). */
final class VmStreamSocketPairNativeTest extends TestCase
{
    public function testUnixStreamPairRoundTrip(): void
    {
        if (!VmStreamSocketPairNative::available()) {
            self::markTestSkipped('FFI socketpair unavailable');
        }

        $pair = VmStreamSocketPairNative::pair(
            StdlibConstants::STREAM_PF_UNIX,
            StdlibConstants::STREAM_SOCK_STREAM,
            StdlibConstants::STREAM_IPPROTO_IP
        );
        self::assertIsArray($pair);
        self::assertCount(4, $pair);
        self::assertIsResource($pair[0]);
        self::assertIsResource($pair[1]);
        fwrite($pair[0], 'ok');
        self::assertSame('ok', stream_get_contents($pair[1]));
        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testUnsupportedDomainReturnsFalse(): void
    {
        if (!VmStreamSocketPairNative::available()) {
            self::markTestSkipped('FFI socketpair unavailable');
        }

        self::assertFalse(VmStreamSocketPairNative::pair(999, StdlibConstants::STREAM_SOCK_STREAM, 0));
    }
}
