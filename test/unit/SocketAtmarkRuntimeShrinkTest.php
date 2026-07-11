<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** socket_atmark JIT routes through SocketAtmarkJitHelper PHP not JIT stub (#9215). */
final class SocketAtmarkRuntimeShrinkTest extends TestCase
{
    public function testSocketAtmarkCallUsesJitSocketAtmark(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_atmark.php');
        $this->assertStringContainsString('JitSocketAtmark::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testSocketAtmarkJitHelperDelegatesToVmSockets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketAtmarkJitHelper.php');
        $this->assertStringContainsString('VmSocket::fdForLookupKey', $source);
        $this->assertStringContainsString('VmSockets::atmarkForFd', $source);
    }

    public function testSocketAtmarkRuntimeCompilesHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketAtmarkRuntime.php');
        $this->assertStringContainsString('SocketAtmarkJitHelper::atmarkForHandle', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }
}
