<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** socket_atmark JIT via SocketAtmarkJitHelper + JitVmHelperLink::ensureCompiled (#9215, #24831). */
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

    public function testSocketAtmarkRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketAtmarkRuntime.php');
        $this->assertStringContainsString('SocketAtmarkJitHelper::atmarkForHandle', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }
}
