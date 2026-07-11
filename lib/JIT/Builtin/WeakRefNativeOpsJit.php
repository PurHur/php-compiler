<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
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
        $i8p = $context->getTypeFromString('int8*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $slotAsValue = $context->builder->pointerCast(
            $context->builder->intToPtr($slotPtr->getValue(), $i8p),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $slotAsValue
        );
    }

    public static function unsetMapKey(Context $context, JITVariable $htPtr, JITVariable $keyStr): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->pointerCast(
            $context->builder->intToPtr($htPtr->getValue(), $i8p),
            $htPtrTy
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr->getValue()
        );
    }
}
