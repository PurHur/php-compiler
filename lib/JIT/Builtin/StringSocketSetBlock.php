<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_set_block()/socket_set_nonblock() — SocketSetBlockJitHelper (#31285).
 */
final class StringSocketSetBlock
{
    public static function ensureLinked(Context $context): void
    {
        SocketSetBlockRuntime::ensureLinked($context);
    }
}
