<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** socket_create/close JIT routes through SocketCreate/CloseJitHelper PHP (#27394). */
final class SocketCreateRuntimeShrinkTest extends TestCase
{
    public function testSocketCreateCallUsesJitSocketCreate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_create.php');
        $this->assertStringContainsString('JitSocketCreate::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketCloseCallUsesJitSocketClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_close.php');
        $this->assertStringContainsString('JitSocketClose::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketCreateJitHelperDelegatesToVmSockets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('SocketsLibcThinAbi::socket', $source);
        $this->assertStringContainsString('VmSocket::registerJitOwnedFd', $source);
    }

    public function testSocketCloseJitHelperDelegatesToVmSockets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCloseJitHelper.php');
        $this->assertStringContainsString('VmSocket::ownedFdForLookupKey', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::close', $source);
    }

    public function testSocketCreateRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketCreateRuntime.php');
        $this->assertStringContainsString('::createFdArgv', $source);
        $this->assertStringContainsString('__compiler_socket_create_fd', $source);
        $this->assertStringContainsString('__compiler_socket_create_register', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSocketCloseRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketCloseRuntime.php');
        $this->assertStringContainsString('::closeForHandle', $source);
        $this->assertStringContainsString('__compiler_socket_close', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
    }

    public function testSocketConnectCallUsesJitSocketConnect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_connect.php');
        $this->assertStringContainsString('JitSocketConnect::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketConnectJitHelperExposesConnectArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketConnectJitHelper.php');
        $this->assertStringContainsString('function connectArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::connectInet', $source);
    }

    public function testSocketConnectRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketConnectRuntime.php');
        $this->assertStringContainsString('::connectArgv', $source);
        $this->assertStringContainsString('SocketConnectJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_connect', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketCreateCloseHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SocketCreateJitHelper.php', $spine);
        $this->assertStringContainsString('SocketCloseJitHelper.php', $spine);
        $this->assertStringContainsString('SocketConnectJitHelper.php', $spine);
        $this->assertStringContainsString('JitSocketCreate.php', $spine);
        $this->assertStringContainsString('JitSocketClose.php', $spine);
        $this->assertStringContainsString('JitSocketConnect.php', $spine);
        $this->assertStringContainsString('SocketCreateRuntime.php', $spine);
        $this->assertStringContainsString('SocketCloseRuntime.php', $spine);
        $this->assertStringContainsString('SocketConnectRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketConnect.php', $spine);
    }
}
