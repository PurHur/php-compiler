<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_bind() — SocketCreateJitHelper::bindArgv (#31241).
 */
final class StringSocketBind
{
    public static function ensureLinked(Context $context): void
    {
        SocketBindListenRuntime::ensureLinked($context);
    }
}
