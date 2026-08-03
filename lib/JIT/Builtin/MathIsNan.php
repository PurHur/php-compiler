<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\JitIsNanKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for MathIsNan (#15188/#15174/#15173, #27021).
 * Direct fcmp leaf — NestedJIT helpers that called \is_* re-entered the bridge.
 */
final class MathIsNan
{
    public static function ensureLinked(Context $context): void
    {
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        return JitIsNanKernel::invoke($context, $num);
    }
}
