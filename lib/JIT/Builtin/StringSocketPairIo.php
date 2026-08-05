<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link facade for socket_create_pair/write/read (#27423). */
final class StringSocketPairIo
{
    public static function ensureLinked(Context $context): void
    {
        SocketPairIoRuntime::ensureLinked($context);
    }
}
