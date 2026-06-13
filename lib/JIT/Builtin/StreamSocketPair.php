<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream_socket_pair() (php-src ext/standard/streams.c; #3437 phase 2). */
final class StreamSocketPair
{
    public static function ensureLinked(Context $context): void
    {
        StreamSocketPairJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
