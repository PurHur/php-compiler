<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for stream read/position/lock helpers (#5343 phase 4). */
final class StreamRead
{
    public static function ensureLinked(Context $context): void
    {
        StreamReadJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
