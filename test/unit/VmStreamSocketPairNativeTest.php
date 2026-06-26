<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamSocketPairNative;
use PHPCompiler\ext\standard\VmStreamSocketPairPure;

/** VmStreamSocketPairPure / VmStreamSocketPairNative — no socketpair FFI (#12253). */
final class VmStreamSocketPairNativeTest extends TestCase
{
    public function testVmStreamSocketPairNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketPairNative.php');
        $this->assertStringContainsString('VmStreamSocketPairPure::', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('\\FFI', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSocketPairPure.php');
        $this->assertStringContainsString('stream_socket_pair', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testUnixStreamPairRoundTrip(): void
    {
        if (!VmStreamSocketPairNative::available()) {
            self::markTestSkipped('stream_socket_pair unavailable');
        }

        $pair = VmStreamSocketPairNative::pair(
            StdlibConstants::STREAM_PF_UNIX,
            StdlibConstants::STREAM_SOCK_STREAM,
            StdlibConstants::STREAM_IPPROTO_IP
        );
        self::assertIsArray($pair);
        self::assertCount(4, $pair);
        self::assertIsInt($pair[0]);
        self::assertIsInt($pair[1]);

        $fp0 = VmFs::lookupResource($pair[0]);
        $fp1 = VmFs::lookupResource($pair[1]);
        self::assertIsResource($fp0);
        self::assertIsResource($fp1);
        fwrite($fp0, 'ok');
        self::assertSame('ok', stream_get_contents($fp1));
        fclose($fp0);
        fclose($fp1);
    }

    public function testUnsupportedDomainReturnsFalse(): void
    {
        if (!VmStreamSocketPairNative::available()) {
            self::markTestSkipped('stream_socket_pair unavailable');
        }

        self::assertFalse(VmStreamSocketPairNative::pair(999, StdlibConstants::STREAM_SOCK_STREAM, 0));
    }
}
