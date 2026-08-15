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
        $this->assertStringContainsString('JitSocketShutdown.php', $spine);
        $this->assertStringContainsString('SocketCreateRuntime.php', $spine);
        $this->assertStringContainsString('SocketCloseRuntime.php', $spine);
        $this->assertStringContainsString('SocketConnectRuntime.php', $spine);
        $this->assertStringContainsString('SocketBindListenRuntime.php', $spine);
        $this->assertStringContainsString('SocketErrorRuntime.php', $spine);
        $this->assertStringContainsString('SocketShutdownRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketConnect.php', $spine);
        $this->assertStringContainsString('StringSocketBind.php', $spine);
        $this->assertStringContainsString('StringSocketListen.php', $spine);
        $this->assertStringContainsString('StringSocketAccept.php', $spine);
        $this->assertStringContainsString('StringSocketCreateListen.php', $spine);
        $this->assertStringContainsString('StringSocketError.php', $spine);
        $this->assertStringContainsString('StringSocketShutdown.php', $spine);
    }

    public function testSocketShutdownCallUsesJitSocketShutdown(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_shutdown.php');
        $this->assertStringContainsString('JitSocketShutdown::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketShutdownJitHelperExposesShutdownArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function shutdownArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::shutdown', $source);
    }

    public function testSocketShutdownRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketShutdownRuntime.php');
        $this->assertStringContainsString('::shutdownArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_shutdown', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSocketSendtoCallUsesJitSocketSendto(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_sendto.php');
        $this->assertStringContainsString('JitSocketSendto::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketSendtoJitHelperExposesSendtoArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function sendtoArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::sendtoInet', $source);
    }

    public function testSocketSendtoRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketSendtoRuntime.php');
        $this->assertStringContainsString('::sendtoArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_sendto', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSocketGetsocknameCallUsesJitSocketGetsockname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_getsockname.php');
        $this->assertStringContainsString('JitSocketGetsockname::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketGetpeernameCallUsesJitSocketGetpeername(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_getpeername.php');
        $this->assertStringContainsString('JitSocketGetpeername::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketGetSocknameJitHelperExposesNameArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function getsocknameOkArgv', $source);
        $this->assertStringContainsString('function getpeernameOkArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::getsocknameInet', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::getpeernameInet', $source);
    }

    public function testSocketGetSocknameRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketGetSocknameRuntime.php');
        $this->assertStringContainsString('::getsocknameOkArgv', $source);
        $this->assertStringContainsString('::getpeernameOkArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_getsockname', $source);
        $this->assertStringContainsString('__compiler_socket_getpeername', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketGetSocknameHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketGetsockname.php', $spine);
        $this->assertStringContainsString('JitSocketGetpeername.php', $spine);
        $this->assertStringContainsString('SocketGetSocknameRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketGetSockname.php', $spine);
    }

    public function testSocketSendCallUsesJitSocketSend(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_send.php');
        $this->assertStringContainsString('JitSocketSend::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketRecvCallUsesJitSocketRecv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_recv.php');
        $this->assertStringContainsString('JitSocketRecv::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketSendRecvJitHelperExposesSendRecvArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function sendArgv', $source);
        $this->assertStringContainsString('function recvArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::send', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::recv', $source);
    }

    public function testSocketSendRecvRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketSendRecvRuntime.php');
        $this->assertStringContainsString('::sendArgv', $source);
        $this->assertStringContainsString('::recvArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_send', $source);
        $this->assertStringContainsString('__compiler_socket_recv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketSendRecvHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketSend.php', $spine);
        $this->assertStringContainsString('JitSocketRecv.php', $spine);
        $this->assertStringContainsString('SocketSendRecvRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketSendRecv.php', $spine);
    }

    public function testSocketSetOptionCallUsesJitSocketSetOption(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_set_option.php');
        $this->assertStringContainsString('JitSocketSetOption::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketGetOptionCallUsesJitSocketGetOption(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_get_option.php');
        $this->assertStringContainsString('JitSocketGetOption::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketGetSetOptionJitHelperExposesOptionArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function setOptionIntArgv', $source);
        $this->assertStringContainsString('function getOptionIntOkArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::setsockoptInt', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::getsockoptInt', $source);
    }

    public function testSocketGetSetOptionRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketGetSetOptionRuntime.php');
        $this->assertStringContainsString('::setOptionIntArgv', $source);
        $this->assertStringContainsString('::getOptionIntOkArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_set_option_int', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketGetSetOptionHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketSetOption.php', $spine);
        $this->assertStringContainsString('JitSocketGetOption.php', $spine);
        $this->assertStringContainsString('SocketGetSetOptionRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketGetSetOption.php', $spine);
    }


    public function testSocketRecvfromCallUsesJitSocketRecvfrom(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_recvfrom.php');
        $this->assertStringContainsString('JitSocketRecvfrom::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketRecvfromJitHelperExposesRecvfromArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCreateJitHelper.php');
        $this->assertStringContainsString('function recvfromArgv', $source);
        $this->assertStringContainsString('SocketsLibcThinAbi::recvfromInet', $source);
    }

    public function testSocketRecvfromRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketRecvfromRuntime.php');
        $this->assertStringContainsString('::recvfromArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_recvfrom', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketRecvfromHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketRecvfrom.php', $spine);
        $this->assertStringContainsString('SocketRecvfromRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketRecvfrom.php', $spine);
    }

    public function testSpineBundleIncludesSocketSendtoHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketSendto.php', $spine);
        $this->assertStringContainsString('SocketSendtoRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketSendto.php', $spine);
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

    public function testSocketSetBlockCallUsesJitSocketSetBlock(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_set_block.php');
        $this->assertStringContainsString('JitSocketSetBlock::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketSetNonblockCallUsesJitSocketSetNonblock(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_set_nonblock.php');
        $this->assertStringContainsString('JitSocketSetNonblock::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketSetBlockJitHelperDelegatesToVmSockets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketSetBlockJitHelper.php');
        $this->assertStringContainsString('SocketCreateJitHelper::fdForHandleArgv', $source);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketSetBlockRuntime.php');
        $this->assertStringContainsString('SocketCreateJitHelper.php', $runtime);
        $this->assertStringContainsString('StringSocketPairIo::ensureLinked', $runtime);
    }

    public function testSocketSetBlockRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketSetBlockRuntime.php');
        $this->assertStringContainsString('::fdForHandleArgv', $source);
        $this->assertStringContainsString('SocketCreateJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_set_block', $source);
        $this->assertStringContainsString('__compiler_socket_set_nonblock', $source);
        $this->assertStringContainsString('lookupFunction(\'fcntl\')', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
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

    public function testSpineBundleIncludesSocketSetBlockHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SocketSetBlockJitHelper.php', $spine);
        $this->assertStringContainsString('JitSocketSetBlock.php', $spine);
        $this->assertStringContainsString('JitSocketSetNonblock.php', $spine);
        $this->assertStringContainsString('SocketSetBlockRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketSetBlock.php', $spine);
    }

    public function testSocketCmsgSpaceCallUsesJitSocketCmsgSpace(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_cmsg_space.php');
        $this->assertStringContainsString('JitSocketCmsgSpace::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketCmsgSpaceJitHelperIsPureMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketCmsgSpaceJitHelper.php');
        $this->assertStringContainsString('cmsgSpaceArgv', $source);
        $this->assertStringContainsString('cmsgAlign', $source);
        $this->assertStringNotContainsString('VmSocketMsg::', $source);
        $this->assertStringNotContainsString('SocketsLibcThinAbi::', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\FFI::/', $source);
        $got = \PHPCompiler\ext\sockets\SocketCmsgSpaceJitHelper::cmsgSpaceArgv(1, 1, 0);
        $this->assertSame(16, $got);
        $this->assertSame(24, \PHPCompiler\ext\sockets\SocketCmsgSpaceJitHelper::cmsgSpaceArgv(1, 1, 1));
    }

    public function testSocketCmsgSpaceRuntimeUsesJitVmHelperLinkEnsureBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketCmsgSpaceRuntime.php');
        $this->assertStringContainsString('::cmsgSpaceArgv', $source);
        $this->assertStringContainsString('__compiler_socket_cmsg_space', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketCmsgSpaceHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketCmsgSpace.php', $spine);
        $this->assertStringContainsString('SocketCmsgSpaceJitHelper.php', $spine);
        $this->assertStringContainsString('SocketCmsgSpaceRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketCmsgSpace.php', $spine);
    }

    public function testSocketSendmsgCallUsesJitSocketSendmsg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_sendmsg.php');
        $this->assertStringContainsString('JitSocketSendmsg::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketRecvmsgCallUsesJitSocketRecvmsg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_recvmsg.php');
        $this->assertStringContainsString('JitSocketRecvmsg::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketSendmsgUsesPairIoWriteBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/JitSocketSendmsg.php');
        $this->assertStringContainsString('__compiler_socket_write', $source);
        $this->assertStringContainsString('StringSocketSendRecvMsg::ensureLinked', $source);
        $this->assertStringContainsString('readStringKeyHashtable', $source);
    }

    public function testSocketRecvmsgUsesPairIoReadBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/JitSocketRecvmsg.php');
        $this->assertStringContainsString('__compiler_socket_read', $source);
        $this->assertStringContainsString('StringSocketSendRecvMsg::ensureLinked', $source);
        $this->assertStringContainsString('__value__writeHashtable', $source);
    }

    public function testSocketSendRecvMsgRuntimeReusesPairIo(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketSendRecvMsgRuntime.php');
        $this->assertStringContainsString('SocketPairIoRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketSendRecvMsgHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketSendmsg.php', $spine);
        $this->assertStringContainsString('JitSocketRecvmsg.php', $spine);
        $this->assertStringContainsString('SocketSendRecvMsgRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketSendRecvMsg.php', $spine);
    }

    public function testSocketAddrinfoLookupCallUsesJitSocketAddrinfoLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_addrinfo_lookup.php');
        $this->assertStringContainsString('JitSocketAddrinfoLookup::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketAddrinfoExplainCallUsesJitSocketAddrinfoExplain(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_addrinfo_explain.php');
        $this->assertStringContainsString('JitSocketAddrinfoExplain::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketAddrinfoConnectCallUsesJitSocketAddrinfoConnect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_addrinfo_connect.php');
        $this->assertStringContainsString('JitSocketAddrinfoConnect::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketAddrinfoBindCallUsesJitSocketAddrinfoBind(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/socket_addrinfo_bind.php');
        $this->assertStringContainsString('JitSocketAddrinfoBind::invoke', $source);
        $this->assertStringNotContainsString('JIT lowering not implemented', $source);
    }

    public function testSocketAddrinfoJitHelperExposesLookupArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sockets/SocketAddrinfoJitHelper.php');
        $this->assertStringContainsString('function lookupCountConstArgv', $source);
        $this->assertStringContainsString('function registerArgv', $source);
        $this->assertStringContainsString('function socketFdArgv', $source);
        $this->assertStringContainsString('function syntheticIpv4Count', $source);
        $this->assertStringContainsString('VmAddressInfo::registerJitSnapshot', $source);
    }

    public function testSocketAddrinfoRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketAddrinfoRuntime.php');
        $this->assertStringContainsString('::lookupCountConstArgv', $source);
        $this->assertStringContainsString('SocketAddrinfoJitHelper.php', $source);
        $this->assertStringContainsString('__compiler_socket_addrinfo_lookup', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('__hashtable__setObjectAt', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
    }

    public function testSpineBundleIncludesSocketAddrinfoHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSocketAddrinfoLookup.php', $spine);
        $this->assertStringContainsString('JitSocketAddrinfoExplain.php', $spine);
        $this->assertStringContainsString('JitSocketAddrinfoConnect.php', $spine);
        $this->assertStringContainsString('JitSocketAddrinfoBind.php', $spine);
        $this->assertStringContainsString('SocketAddrinfoJitHelper.php', $spine);
        $this->assertStringContainsString('SocketAddrinfoRuntime.php', $spine);
        $this->assertStringContainsString('StringSocketAddrinfo.php', $spine);
    }
}
