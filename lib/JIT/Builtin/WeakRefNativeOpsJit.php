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
 * Thin LLVM bridges for weakref clear-on-free native ops (#15968).
 *
 * php-src: Zend/zend_weakrefs.c — slot null + map key unset
 */
final class WeakRefNativeOpsJit
{
    public static function nullSlot(Context $context, JITVariable $slotPtr): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $slotAsValue = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $slotPtr),
            $valuePtr
        );
        // Weak referent slots are non-refcounted (zend_weakrefs.c / VM clearWeakTarget).
        // Do not __value__writeNull — that valueDelrefs and would double-free (#26795).
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(
                \PHPCompiler\JIT\Variable::TYPE_NULL,
                false
            ),
            $context->builder->structGep($slotAsValue, $valueMap['type'])
        );
    }

    public static function unsetMapKey(Context $context, JITVariable $htPtr, JITVariable $keyStr): void
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $ht = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $htPtr),
            $htPtrTy
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            self::stringFromVar($context, $keyStr)
        );
    }

    /** Nested-helper i64 / KIND_VARIABLE long slot → i64 (#21109). */
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

        return JitLongArg::lower($context, $var, 'phpc_weakref pointer');
    }

    private static function stringFromVar(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return JitStringArg::lowerDominating($context, $arg, 'phpc_weakref map key');
        }

        $raw = $arg->value;
        if (JitNestedHelperCoerce::isValueBox($context, $raw)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw)
            );
        }
        $ty = $context->getStringFromType($raw->typeOf());
        if ('__string__*' === $ty || '__string__' === $ty) {
            return $raw;
        }

        throw new \LogicException('WeakRefNativeOpsJit: expected string key, got '.$ty);
    }
}
