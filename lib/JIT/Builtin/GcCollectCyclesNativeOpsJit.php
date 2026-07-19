<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
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

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidpp = $context->getTypeFromString('void**');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $objPtrTy
        );
        $base = $context->builder->pointerCast($obj, $i8p);
        $headerSize = self::objectHeaderSizeConst($context);
        $slotOff = $context->builder->add(
            $headerSize,
            $context->builder->mul(
                $context->builder->zext(self::i64FromVar($context, $slotIndex), $sizeT),
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
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $objPtrTy
        );
        $refcount = self::loadObjectRefcount($context, $obj);

        return $context->builder->sext($refcount, $i64);
    }

    public static function freeObject(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('phpc_gc_free_object'),
            $obj
        );
    }

    public static function destructTryInvoke(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('phpc_destruct_try_invoke'),
            $obj
        );
    }

    public static function releaseObjectStorage(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('phpc_object_release_storage'),
            $obj
        );
    }

    public static function objectIsConstructed(Context $context, JITVariable $objPtr): Value
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $objPtrTy
        );
        $constructed = self::loadObjectConstructed($context, $obj);

        return $context->builder->icmp(
            Builder::INT_NE,
            $constructed,
            $i8->constInt(0, false)
        );
    }

    public static function invokeDestructor(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $objPtrTy
        );
        $context->builder->call(
            $context->lookupFunction('__object__invoke_destructor'),
            $obj
        );
    }

    public static function notifyObjectFreed(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('phpc_gc_notify_object_freed'),
            $obj
        );
    }

    public static function mmFree(Context $context, JITVariable $objPtr): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);

        $i8p = $context->getTypeFromString('int8*');
        $obj = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $objPtr),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $obj
        );
    }

    /** Nested-helper i64 / KIND_VARIABLE long slot → i64 (#21109). */
    private static function i64FromVar(Context $context, JITVariable $var): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type) {
            $raw = $var->value;
            $ty = $context->getStringFromType($raw->typeOf());
            if ('int64' === $ty || 'long long' === $ty) {
                return $raw;
            }

            return $context->builder->load($raw);
        }

        return JitLongArg::lower($context, $var, 'phpc_gc native pointer');
    }

    private static function loadObjectConstructed(Context $context, Value $obj): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null !== $objMap && isset($objMap['constructed'])) {
            return $context->builder->load(
                $context->builder->pointerCast(
                    $context->builder->structGep($obj, $objMap['constructed']),
                    $i8->pointerType(0)
                )
            );
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($obj, $i8p);
        $constructedPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($raw, $i32->constInt(16, false)),
            $i8->pointerType(0)
        );

        return $context->builder->load($constructedPtr);
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
