<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for socket_accept() (#31242). */
final class StringSocketAccept
{
    public static function ensureLinked(Context $context): void
    {
        SocketBindListenRuntime::ensureLinked($context);
    }
}
