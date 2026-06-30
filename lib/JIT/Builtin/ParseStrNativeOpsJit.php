<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
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
        $keyStr = self::loadStringArg($context, $key);
        $valStr = self::loadStringArg($context, $value);
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
        $keyStr = self::loadStringArg($context, $key);
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
        $valStr = self::loadStringArg($context, $value);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext($index->getValue(), $sizeT);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $idx,
            $valStr
        );
    }

    public static function setHashtableAt(Context $context, JITVariable $htPtr, JITVariable $index, JITVariable $childPtr): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $childHt = self::htFromI64($context, $childPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->zext($index->getValue(), $sizeT);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $ht,
            $idx,
            $childHt
        );
    }

    private static function htFromI64(Context $context, JITVariable $ptr): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');

        return JitNestedHelperCoerce::i64ToTypedPtr($context, $ptr->getValue(), $htPtrTy);
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        $raw = $arg->getValue();
        if (JitNestedHelperCoerce::isValueBox($context, $raw)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw)
            );
        }
        $ty = $context->getStringFromType($raw->typeOf());
        if ('__string__*' === $ty) {
            return $context->builder->load($raw);
        }
        if ('__string__' === $ty) {
            return $raw;
        }

        throw new \LogicException('ParseStrNativeOpsJit: expected string argument, got '.$ty);
    }
}
