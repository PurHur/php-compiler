<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\sockets\VmSockets;
use PHPUnit\Framework\TestCase;

/** VmSockets libc sockatmark FFI without host socket_atmark() preference (#7998, #6544). */
final class VmSocketsRuntimeShrinkTest extends TestCase
{
    public function testAtmarkPrefersLibcFfiBeforeHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/VmSockets.php');
        $this->assertStringContainsString('sockatmark', $source);
        $posFfi = strpos($source, '$ffi->sockatmark');
        $posHost = strpos($source, '\\socket_atmark(');
        $this->assertNotFalse($posFfi);
        $this->assertNotFalse($posHost);
        $this->assertLessThan($posHost, $posFfi, 'libc sockatmark must run before host socket_atmark fallback');
    }

    public function testSocketAtmarkBuiltinUsesBuiltinExecute(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_atmark.php');
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $source);
        $this->assertStringContainsString('VmSockets::atmark', $source);
        $this->assertStringNotContainsString('VM delegates to host', $source);
    }

    public function testSockatmarkFfiAvailableOnLinuxHarness(): void
    {
        if (!VmSockets::isAtmarkSupported()) {
            $this->markTestSkipped('libc sockatmark FFI unavailable on this host');
        }

        $this->assertTrue(VmSockets::isAtmarkSupported());
    }
}
