<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_recvfrom() — SocketCreateJitHelper (#31332).
 */
final class StringSocketRecvfrom
{
    public static function ensureLinked(Context $context): void
    {
        SocketRecvfromRuntime::ensureLinked($context);
    }
}
