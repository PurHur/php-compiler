<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for socket_sendmsg()/socket_recvmsg() (#31356).
 *
 * iov-only NestedJIT path reuses {@see SocketPairIoRuntime} LLVM write(2)/read(2)
 * bridges — NestedJIT FFI send/recv/msghdr returns 0 under thin AOT (#27423).
 *
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_sendmsg|socket_recvmsg)
 */
final class SocketSendRecvMsgRuntime
{
    public static function ensureLinked(Context $context): void
    {
        SocketPairIoRuntime::ensureLinked($context);
    }
}
