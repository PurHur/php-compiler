<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_close() — SocketCloseJitHelper (#27394).
 */
final class StringSocketClose
{
    public static function ensureLinked(Context $context): void
    {
        SocketCloseRuntime::ensureLinked($context);
    }
}
