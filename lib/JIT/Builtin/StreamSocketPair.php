<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for stream_socket_pair() via StreamSocketPairJitHelper PHP (#13710). */
final class StreamSocketPair
{
    public static function ensureLinked(Context $context): void
    {
        StreamSocketPairRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
