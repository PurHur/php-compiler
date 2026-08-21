<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Thin LLVM bridges for writing ParseStrEngine output into native __hashtable__* (#13827).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrNativeOpsJit
{
    public static function alloc(Context $context): Value
    {
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));

        return JitNestedHelperCoerce::ptrToI64($context, $ht);
    }

    public static function setStringKey(Context $context, JITVariable $htPtr, JITVariable $key, JITVariable $value): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $valStr = self::ownedString($context, self::loadStringArg($context, $value));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }

    public static function setStringKeyHashtable(
        Context $context,
        JITVariable $htPtr,
        JITVariable $key,
        JITVariable $childPtr
    ): void {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $childHt = self::htFromI64($context, $childPtr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyStr,
            $childHt
        );
    }

    public static function setStringAt(Context $context, JITVariable $htPtr, JITVariable $index, JITVariable $value): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $valStr = self::ownedString($context, self::loadStringArg($context, $value));
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext(
            JitLongArg::lower($context, $index, 'phpc_native_ht index'),
            $sizeT
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $idx,
            $valStr
        );
    }

    public static function setStringKeyLong(Context $context, JITVariable $htPtr, JITVariable $key, JITVariable $value): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $longVal = JitLongArg::lower($context, $value, 'phpc_native_ht long value');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $longVal
        );
    }

    public static function setHashtableAt(Context $context, JITVariable $htPtr, JITVariable $index, JITVariable $childPtr): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $childHt = self::htFromI64($context, $childPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext(
            JitLongArg::lower($context, $index, 'phpc_native_ht index'),
            $sizeT
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $ht,
            $idx,
            $childHt
        );
    }

    public static function setLongAt(Context $context, JITVariable $htPtr, JITVariable $index, JITVariable $value): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext(
            JitLongArg::lower($context, $index, 'phpc_native_ht index'),
            $sizeT
        );
        $longVal = JitLongArg::lower($context, $value, 'phpc_native_ht long value');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $idx,
            $longVal
        );
    }

    /** Packed null hole for SplFixedArray unserialize (#33640 / php-src spl_fixedarray.c). */
    public static function setNullAt(Context $context, JITVariable $htPtr, JITVariable $index): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext(
            JitLongArg::lower($context, $index, 'phpc_native_ht index'),
            $sizeT
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setNullAt'),
            $ht,
            $idx
        );
    }

    /** String-key bool (NestedJIT passes 0/1 long) — ArrayObject bag `b:` (#33670). */
    public static function setStringKeyBool(Context $context, JITVariable $htPtr, JITVariable $key, JITVariable $value): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $longVal = JitLongArg::lower($context, $value, 'phpc_native_ht bool value');
        $i1 = $context->getTypeFromString('int1');
        $asBool = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $longVal,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        // icmp already yields i1; keep a named cast path if the builder returns i8 elsewhere.
        if ($asBool->typeOf() !== $i1) {
            $asBool = $context->builder->trunc($asBool, $i1);
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $asBool
        );
    }

    /** String-key null — ArrayObject bag `N;` (#33670). */
    public static function setStringKeyNull(Context $context, JITVariable $htPtr, JITVariable $key): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyNull'),
            $ht,
            $keyStr
        );
    }

    /**
     * String-key float from serialize `d:` digit text via strtod (#33670).
     * NestedJIT-safe — no float local in the fill helper.
     */
    public static function setStringKeyDoubleFromString(
        Context $context,
        JITVariable $htPtr,
        JITVariable $key,
        JITVariable $digitStr
    ): void {
        $ht = self::htFromI64($context, $htPtr);
        $keyStr = self::ownedString($context, self::loadStringArg($context, $key));
        $digits = self::ownedString($context, self::loadStringArg($context, $digitStr));
        $dbl = JitLongArg::lowerStringToDouble($context, $digits);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyDouble'),
            $ht,
            $keyStr,
            $dbl
        );
    }

    private static function htFromI64(Context $context, JITVariable $ptr): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');

        return JitNestedHelperCoerce::i64ToTypedPtr($context, self::i64FromVar($context, $ptr), $htPtrTy);
    }

    private static function i64FromVar(Context $context, JITVariable $var): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $var->type) {
            $raw = $var->value;
            $ty = $context->getStringFromType($raw->typeOf());
            if ('int64' === $ty || 'long long' === $ty) {
                return $raw;
            }

            return $context->builder->load($raw);
        }

        return JitLongArg::lower($context, $var, 'phpc_native_ht pointer');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return JitStringArg::lowerDominating($context, $arg, 'phpc_native_ht string argument');
        }

        $raw = $arg->value;
        if (JitNestedHelperCoerce::isValueBox($context, $raw)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw)
            );
        }
        $ty = $context->getStringFromType($raw->typeOf());
        if ('__string__*' === $ty) {
            return $raw;
        }
        if ('__string__' === $ty) {
            return $raw;
        }

        throw new \LogicException('ParseStrNativeOpsJit: expected string argument, got '.$ty);
    }

    /** Own Nested JIT temps/views before HT store (#5965). */
    private static function ownedString(Context $context, Value $str): Value
    {
        return $context->builder->call($context->lookupFunction('__string__separate'), $str);
    }
}
