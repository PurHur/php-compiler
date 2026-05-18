<?php

declare(strict_types=1);

/**
 * Allocate and write boxed {@see __value__} slots in JIT code.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitValueBox
{
    public static function alloc(Context $context): Value
    {
        return $context->builder->alloca($context->getTypeFromString('__value__'));
    }

    public static function pointer(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast(
            $slot,
            $context->getTypeFromString('__value__*')
        );
    }

    public static function writeLong(Context $context, Value $slot, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            self::pointer($context, $slot),
            $long
        );
    }

    public static function writeBool(Context $context, Value $slot, Value $bool): void
    {
        $map = $context->structFieldMap['__value__'];
        $ptr = self::pointer($context, $slot);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($ptr, $map['type'])
        );
        $boolByte = $context->builder->truncOrBitCast($bool, $i8);
        $valueField = $context->builder->structGep($ptr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($boolByte, $firstByte);
    }
}
