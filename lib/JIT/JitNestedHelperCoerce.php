<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

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

    public static function isNullPtr(Context $context, Value $ptr, Type $ptrType): Value
    {
        return $context->builder->icmp(
            Builder::INT_EQ,
            $ptr,
            $ptrType->constNull()
        );
    }

    public static function isValueBoxType(Context $context, Type $ty): bool
    {
        $name = $context->getStringFromType($ty);

        return '__value__*' === $name || '__value__' === $name;
    }

    public static function isValueBox(Context $context, Value $value): bool
    {
        return self::isValueBoxType($context, $value->typeOf());
    }

    public static function isHelperResultNull(Context $context, Value $raw): Value
    {
        if (self::isValueBox($context, $raw)) {
            return self::isNullPtr($context, $raw, $context->getTypeFromString('__value__*'));
        }

        return self::isNullPtr($context, $raw, $raw->typeOf());
    }

    public static function coerceArgForHelper(Context $context, Value $arg, Type $wantTy): Value
    {
        $haveTy = $arg->typeOf();
        if ($haveTy === $wantTy) {
            return $arg;
        }
        $haveStr = $context->getStringFromType($haveTy);
        $wantStr = $context->getStringFromType($wantTy);
        if ('__hashtable__*' === $haveStr && '__object__*' === $wantStr) {
            return $context->builder->bitcast($arg, $wantTy);
        }
        if ('__object__*' === $haveStr && '__hashtable__*' === $wantStr) {
            return $context->builder->bitcast($arg, $wantTy);
        }
        if ('__string__*' === $haveStr && self::isValueBoxType($context, $wantTy)) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $arg
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('__hashtable__*' === $haveStr && self::isValueBoxType($context, $wantTy)) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $slot),
                $arg
            );

            return JitValueBox::pointer($context, $slot);
        }
        if (Type::KIND_INTEGER === $wantTy->getKind() && Type::KIND_INTEGER === $haveTy->getKind()) {
            if (('int8' === $haveStr || 'i8' === $haveStr) && ('int64' === $wantStr || 'long long' === $wantStr)) {
                return $context->builder->zext($arg, $wantTy);
            }
            if ('int32' === $haveStr && ('int64' === $wantStr || 'long long' === $wantStr)) {
                return $context->builder->sext($arg, $wantTy);
            }
            if (('int64' === $haveStr || 'long long' === $haveStr) && 'int32' === $wantStr) {
                return $context->builder->trunc($arg, $wantTy);
            }
            if (('int1' === $haveStr || 'bool' === $haveStr) && ('int32' === $wantStr || 'int64' === $wantStr || 'long long' === $wantStr)) {
                return $context->builder->zext($arg, $wantTy);
            }
        }
        if (Type::KIND_POINTER === $wantTy->getKind() && Type::KIND_POINTER === $haveTy->getKind()) {
            return $context->builder->bitcast($arg, $wantTy);
        }

        return $arg;
    }

    /**
     * @param list<Value> $args
     */
    public static function callHelper(Context $context, LlvmFunction $helper, array $args): Value
    {
        $coerced = [];
        for ($i = 0, $n = \count($args); $i < $n; ++$i) {
            $coerced[] = self::coerceArgForHelper($context, $args[$i], $helper->getParam($i)->typeOf());
        }

        return $context->builder->call($helper, ...$coerced);
    }

    /** Nested helpers may return {@see __object__*} or {@see __value__*} for HashTable. */
    public static function coerceToHashtablePtr(Context $context, Value $raw): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $have = $raw->typeOf();
        if ($have === $htPtr) {
            return $raw;
        }
        $valuePtr = $context->getTypeFromString('__value__*');
        if ($have === $valuePtr) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $raw
            );
        }

        return $context->builder->bitcast($raw, $htPtr);
    }

    /** Nested JIT helpers may return i64, i32, or i1 regardless of bridge expectation. */
    public static function coerceHelperScalarResult(Context $context, Value $raw, Type $toType): Value
    {
        $from = $raw->typeOf();
        if ($from === $toType) {
            return $raw;
        }
        $fromStr = $context->getStringFromType($from);
        $toStr = $context->getStringFromType($toType);
        if (('int1' === $fromStr || 'bool' === $fromStr) && ('int32' === $toStr || 'int64' === $toStr || 'long long' === $toStr)) {
            return $context->builder->zext($raw, $toType);
        }
        if ('int8' === $fromStr && ('int32' === $toStr || 'int64' === $toStr || 'long long' === $toStr)) {
            return $context->builder->zext($raw, $toType);
        }
        if (('int64' === $fromStr || 'long long' === $fromStr) && ('int32' === $toStr || 'int1' === $toStr || 'bool' === $toStr)) {
            return 'int1' === $toStr || 'bool' === $toStr
                ? $context->builder->truncOrBitCast($raw, $toType)
                : $context->builder->trunc($raw, $toType);
        }
        if ('int32' === $fromStr && ('int64' === $toStr || 'long long' === $toStr)) {
            return $context->builder->sext($raw, $toType);
        }

        return $raw;
    }

    /** Nested helpers may return {@see __value__*} for nullable string. */
    public static function extractStringPtrFromHelperResult(Context $context, Value $raw): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        if ($raw->typeOf() === $strPtr) {
            return $raw;
        }
        if (self::isValueBox($context, $raw)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $raw
            );
        }

        return $context->builder->bitcast($raw, $strPtr);
    }

    public static function coerceBridgeResult(Context $context, Value $raw, Type $wantTy): Value
    {
        $haveTy = $raw->typeOf();
        if ($haveTy === $wantTy) {
            return $raw;
        }
        $wantStr = $context->getStringFromType($wantTy);
        if ('__hashtable__*' === $wantStr) {
            return self::coerceToHashtablePtr($context, $raw);
        }
        if ('__string__*' === $wantStr) {
            return self::extractStringPtrFromHelperResult($context, $raw);
        }
        if (Type::KIND_INTEGER === $wantTy->getKind()) {
            return self::coerceHelperScalarResult($context, $raw, $wantTy);
        }
        if (Type::KIND_POINTER === $wantTy->getKind() && Type::KIND_POINTER === $haveTy->getKind()) {
            return $context->builder->bitcast($raw, $wantTy);
        }

        return $raw;
    }
}
