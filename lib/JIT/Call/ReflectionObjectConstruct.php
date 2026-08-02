<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionObject::__construct(object $object) — JIT (#20098).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionObject___construct
 */
final class ReflectionObjectConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionObject',
                1,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $target = ReflectionSetup::loadObjectFromArg($context, $args[1]);
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $args[1]);

        self::writeStringProp(
            $context,
            $obj,
            ReflectionSupport::PROP_CLASS_NAME,
            $classNameStr
        );
        self::writeObjectProp(
            $context,
            $obj,
            ReflectionSupport::PROP_OBJECT_TARGET,
            $target
        );
        ReflectionSetup::markConstructed($context, $obj);

        $ret = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $ret)
        );

        return $ret;
    }

    private static function writeStringProp(
        Context $context,
        Value $obj,
        string $propName,
        Value $strPtr
    ): void {
        $slot = $context->type->object->propertySlotFor($obj, 'ReflectionObject', $propName);
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $heapPtr,
            $strPtr
        );
        // Property slots are void**; store void* (not i8*/bytePtr) — LLVM verify (#26828 / #26795).
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
    }

    private static function writeObjectProp(
        Context $context,
        Value $obj,
        string $propName,
        Value $target
    ): void {
        $slot = $context->type->object->propertySlotFor($obj, 'ReflectionObject', $propName);
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $heapPtr,
            $target
        );
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
    }
}
