<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for socket_create_listen() (#31242). */
final class StringSocketCreateListen
{
    public static function ensureLinked(Context $context): void
    {
        SocketBindListenRuntime::ensureLinked($context);
    }
}
