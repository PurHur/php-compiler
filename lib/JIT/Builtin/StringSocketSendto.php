<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_sendto() — SocketCreateJitHelper (#31308).
 */
final class StringSocketSendto
{
    public static function ensureLinked(Context $context): void
    {
        SocketSendtoRuntime::ensureLinked($context);
    }
}
