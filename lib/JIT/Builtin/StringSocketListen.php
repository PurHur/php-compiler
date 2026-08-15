<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_listen() — SocketCreateJitHelper::listenArgv (#31241).
 */
final class StringSocketListen
{
    public static function ensureLinked(Context $context): void
    {
        SocketBindListenRuntime::ensureLinked($context);
    }
}
