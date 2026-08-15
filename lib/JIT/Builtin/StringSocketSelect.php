<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_select() — SocketCreateJitHelper select slots (#31355).
 */
final class StringSocketSelect
{
    public static function ensureLinked(Context $context): void
    {
        SocketSelectRuntime::ensureLinked($context);
    }
}
