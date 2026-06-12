<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\sockets\VmSockets;
use PHPUnit\Framework\TestCase;

/** VmSockets libc sockatmark FFI without host socket_atmark() delegation (#7998, #8176, #6544). */
final class VmSocketsRuntimeShrinkTest extends TestCase
{
    public function testAtmarkUsesLibcFfiOnlyWithoutHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/VmSockets.php');
        $this->assertStringContainsString('sockatmark', $source);
        $this->assertStringNotContainsString('function_exists(', $source);
        $this->assertStringNotContainsString('\\socket_atmark(', $source);
    }

    public function testSocketAtmarkBuiltinUsesBuiltinExecute(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_atmark.php');
        $this->assertStringContainsString('BuiltinExecute::writeReturn', $source);
        $this->assertStringContainsString('VmSockets::atmarkForObject', $source);
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
