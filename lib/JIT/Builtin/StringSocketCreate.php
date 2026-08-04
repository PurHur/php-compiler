<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_create() — SocketCreateJitHelper (#27394).
 */
final class StringSocketCreate
{
    public static function ensureLinked(Context $context): void
    {
        SocketCreateRuntime::ensureLinked($context);
    }
}
