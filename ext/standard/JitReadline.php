<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/** LLVM lowering for readline family — non-interactive builds return false/empty (#3776, #7059). */
final class JitReadline
{
    public static function invoke(Context $context): Value
    {
        return self::invokeBool($context, false);
    }

    public static function invokeBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt($value ? 1 : 0, false)
        );

        return $ptr;
    }

    public static function invokeEmptyArray(Context $context): Value
    {
        return HashTableHelper::alloc($context);
    }

    public static function invokeVoid(Context $context): Value
    {
        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
