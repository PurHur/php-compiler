<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_connect() — SocketCreateJitHelper::connectArgv (#31240).
 */
final class StringSocketConnect
{
    public static function ensureLinked(Context $context): void
    {
        SocketConnectRuntime::ensureLinked($context);
    }
}
