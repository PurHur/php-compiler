<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_strerror/last_error/clear_error — SocketErrorJitHelper (#31270).
 */
final class StringSocketError
{
    public static function ensureLinked(Context $context): void
    {
        SocketErrorRuntime::ensureLinked($context);
    }
}
