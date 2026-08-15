<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_getsockname()/socket_getpeername() — SocketCreateJitHelper (#31293).
 */
final class StringSocketGetSockname
{
    public static function ensureLinked(Context $context): void
    {
        SocketGetSocknameRuntime::ensureLinked($context);
    }
}
