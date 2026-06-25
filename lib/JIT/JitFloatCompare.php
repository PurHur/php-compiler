<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmFloatCompare;
use PHPLLVM\Value;

/**
 * JIT trampoline for native double compare lowering (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmFloatCompare}
 */
final class JitFloatCompare
{
    public static function relationalCompare(
        Context $context,
        int $opType,
        Value $left,
        Value $right
    ): Value {
        return VmFloatCompare::relationalCompare($context, $opType, $left, $right);
    }

    public static function spaceship(Context $context, Value $left, Value $right): Value
    {
        return VmFloatCompare::spaceship($context, $left, $right);
    }
}
