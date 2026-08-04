<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionPropertyIsVirtualRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionProperty::isVirtual() — JIT/AOT (#27516, ext/reflection/php_reflection.c). */
final class ReflectionPropertyIsVirtual implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $classStr = self::readStoredStringBox($context, $obj, ReflectionSupport::PROP_DECLARING_CLASS_NAME);
        $propStr = self::readStoredStringBox($context, $obj, ReflectionSupport::PROP_PROPERTY_NAME);
        $isVirtual = ReflectionPropertyIsVirtualRuntime::invoke($context, $classStr, $propStr);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $isVirtual);

        return $resultSlot;
    }

    private static function readStoredStringBox(Context $context, Value $obj, string $propName): Value
    {
        $slot = $context->type->object->propertySlotFor($obj, 'ReflectionProperty', $propName);
        $loaded = $context->builder->load($slot);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $valuePtr = $context->builder->pointerCast($loaded, $valuePtrTy);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $valuePtr, $valuePtrTy->constNull());

        $fn = BasicBlockHelper::parentFunction($context);
        $readBlock = $fn->appendBasicBlock('refl_prop_virt_str_read');
        $emptyBlock = $fn->appendBasicBlock('refl_prop_virt_str_empty');
        $doneBlock = $fn->appendBasicBlock('refl_prop_virt_str_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtrTy);
        $context->builder->branchIf($isNull, $emptyBlock, $readBlock);

        $context->builder->positionAtEnd($readBlock);
        $read = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $context->builder->store($read, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('int64')->constInt(0, false),
            $context->builder->pointerCast(
                $context->constantFromString(''),
                $context->getTypeFromString('int8*')
            )
        );
        $context->builder->store($empty, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }
}
