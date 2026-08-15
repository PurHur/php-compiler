<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_send()/socket_recv() — SocketCreateJitHelper (#31294).
 */
final class StringSocketSendRecv
{
    public static function ensureLinked(Context $context): void
    {
        SocketSendRecvRuntime::ensureLinked($context);
    }
}
