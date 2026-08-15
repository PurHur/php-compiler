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

    public function testSocketBindCallUsesJitSocketBind(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_bind.php');
        $this->assertStringContainsString('JitSocketBind::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketListenCallUsesJitSocketListen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_listen.php');
        $this->assertStringContainsString('JitSocketListen::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketBindListenJitHelperExposesBindListenArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function bindArgv', $source);
        $this->assertStringContainsString('function listenArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::bindInet', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::listen', $source);
    }

    public function testSocketBindListenRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketBindListenRuntime.php');
        $this->assertStringContainsString('::bindArgv', $source);
        $this->assertStringContainsString('::listenArgv', $source);
        $this->assertStringContainsString('::acceptArgv', $source);
        $this->assertStringContainsString('::createListenFdArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_bind', $source);
        $this->assertStringContainsString('__compiler_socket_listen', $source);
        $this->assertStringContainsString('__compiler_socket_accept', $source);
        $this->assertStringContainsString('__compiler_socket_create_listen_fd', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSocketAcceptCallUsesJitSocketAccept(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_accept.php');
        $this->assertStringContainsString('JitSocketAccept::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketCreateListenCallUsesJitSocketCreateListen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_create_listen.php');
        $this->assertStringContainsString('JitSocketCreateListen::invoke', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testSpineBundleIncludesSocketCreateCloseHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SocketCreateJitHelper.php', $spine);
        $this->assertStringContainsString('SocketCloseJitHelper.php', $spine);
        $this->assertStringContainsString('SocketConnectJitHelper.php', $spine);
        $this->assertStringContainsString('SocketErrorJitHelper.php', $spine);
        $this->assertStringContainsString('JitSocketCreate.php', $spine);
        $this->assertStringContainsString('JitSocketClose.php', $spine);
        $this->assertStringContainsString('JitSocketConnect.php', $spine);
        $this->assertStringContainsString('JitSocketBind.php', $spine);
        $this->assertStringContainsString('JitSocketListen.php', $spine);
        $this->assertStringContainsString('JitSocketAccept.php', $spine);
        $this->assertStringContainsString('JitSocketCreateListen.php', $spine);
        $this->assertStringContainsString('JitSocketStrerror.php', $spine);
        $this->assertStringContainsString('JitSocketLastError.php', $spine);
        $this->assertStringContainsString('JitSocketClearError.php', $spine);
        $this->assertStringContainsString('SocketCreateRuntime.php', $spine);
        $this->assertStringContainsString('SocketCloseRuntime.php', $spine);
        $this->assertStringContainsString('SocketConnectRuntime.php', $spine);
        $this->assertStringContainsString('SocketBindListenRuntime.php', $spine);
        $this->assertStringContainsString('SocketErrorRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketConnect.php', $spine);
        $this->assertStringContainsString('StringSocketBind.php', $spine);
        $this->assertStringContainsString('StringSocketListen.php', $spine);
        $this->assertStringContainsString('StringSocketAccept.php', $spine);
        $this->assertStringContainsString('StringSocketCreateListen.php', $spine);
        $this->assertStringContainsString('StringSocketError.php', $spine);
    }

    public function testSocketStrerrorCallUsesJitSocketStrerror(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_strerror.php');
        $this->assertStringContainsString('JitSocketStrerror::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketLastErrorCallUsesJitSocketLastError(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_last_error.php');
        $this->assertStringContainsString('JitSocketLastError::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketClearErrorCallUsesJitSocketClearError(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_clear_error.php');
        $this->assertStringContainsString('JitSocketClearError::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketErrorJitHelperDelegatesToVmSockets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketErrorJitHelper.php');
        $this->assertStringContainsString('VmSocket::lastErrorForLookupKey', $source);
        $this->assertStringContainsString('VmSocket::clearErrorOptionalForLookupKey', $source);
        $this->assertStringNotContainsString('SocketsLibcThinAbi', $source);
        $this->assertStringNotContainsString('strerrorHostLookupArgv', $source);
    }

    public function testSocketErrorRuntimeUsesJitVmHelperLinkEnsureBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketErrorRuntime.php');
        $this->assertStringContainsString('::lastErrorForHandle', $source);
        $this->assertStringContainsString('::clearErrorForHandle', $source);
        $this->assertStringContainsString('__compiler_socket_strerror', $source);
        $this->assertStringContainsString('lookupFunction(\'strerror\')', $source);
        $this->assertStringContainsString('Unknown host', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }
}
