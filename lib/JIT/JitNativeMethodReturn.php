<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Return typed native LLVM scalars from builtin method {@see Call} handlers (#36205).
 *
 * {@see JIT::assignCallResultOperand} already promotes int1/int64/double to TYPE_NATIVE_*;
 * boxing via {@see JitValueBox::alloc} is only required when a consumer needs __value__*.
 */
final class JitNativeMethodReturn
{
    public static function bool(Context $context, Value $i1): Value
    {
        return $i1;
    }

    public static function long(Context $context, Value $i64): Value
    {
        return $i64;
    }

    public static function longZero(Context $context): Value
    {
        return $context->getTypeFromString('int64')->constInt(0, true);
    }

    public static function boolFalse(Context $context): Value
    {
        return $context->getTypeFromString('int1')->constInt(0, false);
    }
}
