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

    /**
     * Materialize a freshly boxed slot for the callee's parameter type.
     *
     * {@see isValueBoxType} accepts both `__value__*` and `__value__`, but a box
     * slot is addressed by pointer. A helper whose parameter is `__value__` by
     * value needs the slot loaded — passing the pointer instead emitted
     * `call ...(%__value__* %slot)` against a by-value parameter and failed
     * module verification for every helper unit (#22638).
     */
    private static function valueBoxAs(Context $context, Value $slot, Type $wantTy): Value
    {
        $ptr = JitValueBox::pointer($context, $slot);
        if ('__value__' === $context->getStringFromType($wantTy)) {
            return $context->builder->load($ptr);
        }

        return $ptr;
    }

    public static function isHelperResultNull(Context $context, Value $raw): Value
    {
        if (self::isValueBox($context, $raw)) {
            return self::isValueBoxNullOrFalse($context, self::valueBoxPtrFromHelperResult($context, $raw));
        }

        return self::isNullPtr($context, $raw, $raw->typeOf());
    }

    /** Materialize nested-helper {@see __value__} struct returns to a stack slot pointer. */
    public static function valueBoxPtrFromHelperResult(Context $context, Value $raw): Value
    {
        $have = $raw->typeOf();
        $haveStr = $context->getStringFromType($have);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        if ('__value__*' === $haveStr) {
            return $raw;
        }
        if ('__value__' === $haveStr) {
            // Peer {@see JitValueBox::alloc}: re-open insert after entryAlloca — otherwise
            // store/GEP land as orphan instructions under NestedJIT user-script AOT (#20664).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_helper_vbox_materialize');
            $slot = BasicBlockHelper::entryAlloca($context, $have);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_helper_vbox_store');
            $context->builder->store($raw, $slot);

            return $context->builder->pointerCast($slot, $valuePtrTy);
        }

        throw new \LogicException('valueBoxPtrFromHelperResult: unsupported type '.$haveStr);
    }

  private static function isValueBoxNullOrFalse(Context $context, Value $valuePtr): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_helper_vbox_nullish');
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $boolBytePtr = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolByte = $context->builder->load($boolBytePtr);
        $boolFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $boolByte,
            $i8->constInt(0, false)
        );

        return $context->builder->or($isNull, $context->builder->and($isBool, $boolFalse));
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

            return self::valueBoxAs($context, $slot, $wantTy);
        }
        if ('__value__*' === $haveStr && '__value__' === $wantStr) {
            return $context->builder->load($arg);
        }
        if ('__hashtable__*' === $haveStr && self::isValueBoxType($context, $wantTy)) {
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $slot),
                $arg
            );

            return self::valueBoxAs($context, $slot, $wantTy);
        }
        if (('double' === $wantStr || 'float' === $wantStr) && self::isValueBoxType($context, $haveTy)) {
            $extracted = self::extractDoubleFromHelperResult($context, $arg);
            if ('float' === $wantStr && $extracted->typeOf() !== $wantTy) {
                return $context->builder->fpTrunc($extracted, $wantTy);
            }

            return $extracted;
        }
        if (self::isValueBoxType($context, $wantTy) && ('double' === $haveStr || 'float' === $haveStr)) {
            $slot = JitValueBox::alloc($context);
            $asDouble = 'float' === $haveStr ? $context->builder->fpExt($arg, $context->getTypeFromString('double')) : $arg;
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $asDouble
            );

            return self::valueBoxAs($context, $slot, $wantTy);
        }
        if (Type::KIND_INTEGER === $wantTy->getKind() && Type::KIND_INTEGER === $haveTy->getKind()) {
            if (('int8' === $haveStr || 'i8' === $haveStr) && ('int32' === $wantStr || 'int64' === $wantStr || 'long long' === $wantStr)) {
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
            if (('int32' === $haveStr || 'int64' === $haveStr || 'long long' === $haveStr) && ('int1' === $wantStr || 'bool' === $wantStr)) {
                $i32 = $context->getTypeFromString('int32');
                $cmpArg = 'int32' === $haveStr ? $arg : $context->builder->trunc($arg, $i32);

                return $context->builder->icmp(
                    Builder::INT_NE,
                    $cmpArg,
                    $i32->constInt(0, false)
                );
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
        BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_helper_call');
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
        $haveStr = $context->getStringFromType($have);
        if ('__value__*' === $haveStr) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $raw
            );
        }
        if ('__value__' === $haveStr) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                self::valueBoxPtrFromHelperResult($context, $raw)
            );
        }
        // NestedJIT under user-script AOT may lower HashTable returns as i64 (#20664 / #20652).
        if ('int64' === $haveStr || 'long long' === $haveStr) {
            return self::i64ToTypedPtr($context, $raw, $htPtr);
        }
        if (Type::KIND_POINTER === $have->getKind()) {
            return $context->builder->bitcast($raw, $htPtr);
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
        if (('int64' === $fromStr || 'long long' === $fromStr) && ('int32' === $toStr || 'int1' === $toStr || 'bool' === $toStr || 'int8' === $toStr)) {
            return ('int1' === $toStr || 'bool' === $toStr)
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
                self::valueBoxPtrFromHelperResult($context, $raw)
            );
        }

        return $context->builder->bitcast($raw, $strPtr);
    }

    /**
     * NestedJIT *JitHelper methods returning int may box as {@see __value__}; ABI bridges want bare i64 (#20266).
     *
     * Scoped extract (like {@see extractStringPtrFromHelperResult}) — do not put readLong in
     * {@see coerceHelperScalarResult}: bool boxes segfault on readLong (#8555) and broke vm-driver-probe.
     */
    public static function extractLongFromHelperResult(Context $context, Value $raw, Type $toType): Value
    {
        if (self::isValueBox($context, $raw)) {
            $long = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                self::valueBoxPtrFromHelperResult($context, $raw)
            );

            return self::coerceHelperScalarResult($context, $long, $toType);
        }

        return self::coerceHelperScalarResult($context, $raw, $toType);
    }

    /**
     * NestedJIT *JitHelper methods returning float may box as {@see __value__}; ABI bridges want bare double (#20664).
     *
     * Peer {@see extractLongFromHelperResult} — keep readDouble scoped (not in {@see coerceHelperScalarResult}).
     */
    public static function extractDoubleFromHelperResult(Context $context, Value $raw): Value
    {
        $double = $context->getTypeFromString('double');
        $have = $raw->typeOf();
        if ($have === $double) {
            return $raw;
        }
        if (self::isValueBox($context, $raw)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                self::valueBoxPtrFromHelperResult($context, $raw)
            );
        }
        $haveStr = $context->getStringFromType($have);
        // Truncated integer returns (NestedJIT mis-typed float as long) — widen via SIToFP.
        if ('int64' === $haveStr || 'long long' === $haveStr || 'int32' === $haveStr) {
            return $context->builder->siToFp($raw, $double);
        }
        if ('float' === $haveStr) {
            return $context->builder->fpExt($raw, $double);
        }

        return $raw;
    }

    /**
     * NestedJIT *JitHelper methods returning bool may box as {@see __value__}; ABI bridges want bare i1 (#20652).
     *
     * Do not route bool boxes through {@see extractLongFromHelperResult} / {@see __value__readLong}
     * (segfault / always-false — #8555). Peer {@see extractDoubleFromHelperResult}.
     */
    public static function extractBoolFromHelperResult(Context $context, Value $raw): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $have = $raw->typeOf();
        $haveStr = $context->getStringFromType($have);
        if ('int1' === $haveStr || 'bool' === $haveStr) {
            return $raw;
        }
        if (self::isValueBox($context, $raw)) {
            $valuePtr = self::valueBoxPtrFromHelperResult($context, $raw);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'nested_helper_extract_bool');
            $map = $context->structFieldMap['__value__'];
            $i8 = $context->getTypeFromString('int8');
            $valueField = $context->builder->structGep($valuePtr, $map['value']);
            $boolBytePtr = $context->builder->inBoundsGEP(
                $valueField,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt(0, false)
            );
            $boolByte = $context->builder->load($boolBytePtr);

            return $context->builder->icmp(
                Builder::INT_NE,
                $boolByte,
                $i8->constInt(0, false)
            );
        }
        if ('int8' === $haveStr || 'i8' === $haveStr
            || 'int32' === $haveStr || 'int64' === $haveStr || 'long long' === $haveStr) {
            return $context->builder->icmp(
                Builder::INT_NE,
                $raw,
                $have->constInt(0, false)
            );
        }

        return $context->builder->truncOrBitCast($raw, $i1);
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
        if ('double' === $wantStr || 'float' === $wantStr) {
            $extracted = self::extractDoubleFromHelperResult($context, $raw);
            if ('float' === $wantStr && $extracted->typeOf() !== $wantTy) {
                return $context->builder->fpTrunc($extracted, $wantTy);
            }

            return $extracted;
        }
        // Bool bridges (array_is_list / in_array int1) — before integer KIND uses readLong (#20652).
        if ('int1' === $wantStr || 'bool' === $wantStr) {
            return self::extractBoolFromHelperResult($context, $raw);
        }
        if (Type::KIND_INTEGER === $wantTy->getKind()) {
            // Prefer scoped long extract when helper boxed the int (#20266 / rename #20603).
            if (self::isValueBox($context, $raw)) {
                return self::extractLongFromHelperResult($context, $raw, $wantTy);
            }

            return self::coerceHelperScalarResult($context, $raw, $wantTy);
        }
        if (Type::KIND_POINTER === $wantTy->getKind() && Type::KIND_POINTER === $haveTy->getKind()) {
            return $context->builder->bitcast($raw, $wantTy);
        }

        return $raw;
    }
}
