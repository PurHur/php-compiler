<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_shutdown() — SocketCreateJitHelper (#31292).
 */
final class StringSocketShutdown
{
    public static function ensureLinked(Context $context): void
    {
        SocketShutdownRuntime::ensureLinked($context);
    }
}
