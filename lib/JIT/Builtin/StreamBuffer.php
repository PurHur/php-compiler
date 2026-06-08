<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream buffer controls (#5343 phase 4). */
final class StreamBuffer
{
    public static function ensureLinked(Context $context): void
    {
        StreamBufferJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
