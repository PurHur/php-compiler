<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

/**
 * LLVM ABI coercion for nested php-in-PHP JIT helpers (#11206).
 *
 * Scalar helpers use i64; thin bridges zext/trunc. Pointer helpers pass typed pointers through.
 */
final class JitNestedHelperCoerce
{
    public static function scalarToI64(Context $context, Value $value, Type $fromType): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ($fromType === $i64) {
            return $value;
        }

        return $context->builder->zext($value, $i64);
    }

    public static function ptrToI64(Context $context, Value $ptr): Value
    {
        return $context->builder->ptrToInt($ptr, $context->getTypeFromString('int64'));
    }

    public static function i64ToScalar(Context $context, Value $value, Type $toType): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ($toType === $i64) {
            return $value;
        }

        return $context->builder->trunc($value, $toType);
    }

    public static function i64IsZero(Context $context, Value $addrI64): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $addrI64,
            $i64->constInt(0, false)
        );
    }

    public static function i64ToTypedPtr(Context $context, Value $addrI64, Type $ptrType): Value
    {
        $isZero = self::i64IsZero($context, $addrI64);

        return $context->builder->select(
            $isZero,
            $ptrType->constNull(),
            $context->builder->intToPtr($addrI64, $ptrType)
        );
    }
}
