<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamFilterKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for stream_filter_* builtins (#9047, #21041).
 *
 * Always NestedJIT via {@see JitStreamFilterKernel} (no thin deferred stubs).
 */
final class StreamFilter
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamFilterKernel::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
