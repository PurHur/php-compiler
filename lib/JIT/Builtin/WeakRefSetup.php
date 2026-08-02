<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** LLVM helpers for WeakReference / WeakMap JIT (#3667). */
final class WeakRefSetup
{
    public static function loadObjectFromArg(Context $context, Variable $arg): Value
    {
        $raw = $context->helper->loadValue($arg);
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('__object__*' === $rawTy) {
            return $raw;
        }
        if ('__value__' === $rawTy) {
            $slot = JitValueBox::alloc($context);
            $context->builder->store($raw, $slot);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::pointer($context, $slot)
            );
        }
        if ('__value__*' === $rawTy) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $raw
            );
        }

        throw new \LogicException('WeakRef JIT lowering expected object argument');
    }

    public static function requireClassId(Context $context, string $className): int
    {
        $id = $context->type->object->classIdByName($className);
        if (null === $id) {
            throw new \LogicException("JIT class {$className} is not registered");
        }

        return $id;
    }

    public static function bindWeakTarget(Context $context, Value $weakRefObj, Value $targetObj): void
    {
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);

        $slot = $context->type->object->propertySlotFor($weakRefObj, 'WeakReference', '__weak_target');
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        // zend_weakrefs.c: referent slot is non-refcounted (VM weakObject). Do not call
        // __value__writeObject — that addrefs and keeps the object alive under AOT {main}
        // deferred destroy (#26795).
        $valueMap = $context->structFieldMap['__value__'];
        $objPtrTy = $context->getTypeFromString('__object__*');
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_OBJECT, false),
            $context->builder->structGep($heapPtr, $valueMap['type'])
        );
        $ptrField = $context->builder->structGep($heapPtr, $valueMap['value']);
        $objSlot = $context->builder->pointerCast($ptrField, $objPtrTy->pointerType(0));
        $context->builder->store($targetObj, $objSlot);
        // Property slots are void**; store void* (not i8*/bytePtr) — LLVM verify (#26795).
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
        $i8p = $context->getTypeFromString('int8*');
        // Register the property void** itself so clear can null the indirection (#26795).
        $context->builder->call(
            $context->lookupFunction('phpc_weakref_register_ref'),
            $context->builder->pointerCast($targetObj, $i8p),
            $context->builder->pointerCast($slot, $i8p)
        );
    }

    public static function formatObjectKey(Context $context, Value $keyObj, Value $buf, Value $bufLen): void
    {
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('phpc_weakref_format_object_key'),
            $context->builder->pointerCast($keyObj, $i8p),
            $buf,
            $bufLen
        );
    }
}
