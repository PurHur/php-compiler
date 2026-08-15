<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_addrinfo_* — SocketAddrinfoJitHelper (#31357).
 */
final class StringSocketAddrinfo
{
    public static function ensureLinked(Context $context): void
    {
        SocketAddrinfoRuntime::ensureLinked($context);
    }
}
