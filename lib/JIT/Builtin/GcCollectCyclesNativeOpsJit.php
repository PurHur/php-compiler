<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin LLVM bridges for embed GC native scan PHP (#13882).
 *
 * php-src: Zend/zend_gc.c — property slot walk / free
 */
final class GcCollectCyclesNativeOpsJit
{
    public static function childAt(Context $context, JITVariable $objPtr, JITVariable $slotIndex): Value
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidpp = $context->getTypeFromString('void**');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $objI64 = $context->builder->pointerCast($objPtr->getValue(), $i64);
        $obj = $context->builder->pointerCast($objI64, $objPtrTy);
        $base = $context->builder->pointerCast($obj, $i8p);
        $headerSize = self::objectHeaderSizeConst($context);
        $slotOff = $context->builder->add(
            $headerSize,
            $context->builder->mul(
                $context->builder->zext($slotIndex->getValue(), $sizeT),
                $sizeT->constInt(8, false)
            )
        );
        $slotPtr = $context->builder->pointerCast(
            $context->builder->gep($base, $slotOff),
            $voidpp
        );
        $child = $context->builder->call(
            $context->lookupFunction('phpc_gc_slot_read_object'),
            $slotPtr
        );

        return $context->builder->pointerCast($child, $i64);
    }

    public static function objectRefcount(Context $context, JITVariable $objPtr): Value
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $obj = $context->builder->pointerCast($objPtr->getValue(), $objPtrTy);
        $refcount = self::loadObjectRefcount($context, $obj);

        return $context->builder->sext($refcount, $i64);
    }

    public static function freeObject(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $objI64 = $objPtr->getValue();
        $context->builder->call(
            $context->lookupFunction('phpc_gc_free_object'),
            $context->builder->pointerCast($objI64, $i8p)
        );
    }

    private static function objectHeaderSizeConst(Context $context): Value
    {
        $objTy = $context->getTypeFromString('__object__');
        $one = $context->context->int32Type()->constInt(1, false);

        return $context->builder->pointerCast(
            $context->builder->gep($objTy->pointerType(0)->constNull(), $one),
            $context->getTypeFromString('size_t')
        );
    }

    private static function loadObjectRefcount(Context $context, Value $obj): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $objMap = $context->structFieldMap['__object__'] ?? null;
        $refMap = $context->structFieldMap['__ref__'] ?? null;
        if (null !== $objMap && null !== $refMap && isset($objMap['ref'], $refMap['refcount'])) {
            $refField = $context->builder->structGep($obj, $objMap['ref']);
            $refcountPtr = $context->builder->structGep($refField, $refMap['refcount']);

            return $context->builder->load($refcountPtr);
        }
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($obj, $i8p);
        $refcountPtr = $context->builder->pointerCast($raw, $i32->pointerType(0));

        return $context->builder->load($refcountPtr);
    }
}
