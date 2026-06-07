<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for Z_PARAM_NUMBER math operands (php-src math.c; #5613 enum-case TypeError). */
final class JitMathNumberArg
{
    public static function lowerToDouble(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        return JitFdiv::lowerSingleOperand($context, $arg, $argIndex, $paramName, $function, 'number');
    }
}
