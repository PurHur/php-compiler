<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link facade for socket_cmsg_space() — SocketCmsgSpaceJitHelper (#31345).
 */
final class StringSocketCmsgSpace
{
    public static function ensureLinked(Context $context): void
    {
        SocketCmsgSpaceRuntime::ensureLinked($context);
    }
}
