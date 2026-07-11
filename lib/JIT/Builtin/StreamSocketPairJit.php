<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * stream_socket_pair() dispatch — nested JIT compiles StreamSocketPairJitHelper PHP (#13710).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_socket_pair)
 */
final class StreamSocketPairJit
{
    public static function implement(Context $context): void
    {
        StreamSocketPairRuntime::ensureLinked($context);
    }
}
