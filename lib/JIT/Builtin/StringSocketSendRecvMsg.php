<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_sendmsg()/socket_recvmsg() — SocketCreateJitHelper (#31356).
 */
final class StringSocketSendRecvMsg
{
    public static function ensureLinked(Context $context): void
    {
        SocketSendRecvMsgRuntime::ensureLinked($context);
    }
}
