<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for random_int() bound operands — Z_PARAM_LONG via shared sleep helper
 * (strict_types TypeError #29779; enum rejection #5795).
 */
final class JitRandomIntArg
{
    public static function lowerBound(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        return JitSleep::zParamLong($context, $arg, 'random_int', $argIndex, $paramName);
    }
}
