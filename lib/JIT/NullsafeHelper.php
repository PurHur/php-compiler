<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\Type\Object_;

/**
 * LLVM lowering helpers for ?-> nullsafe branch targets (issues #308, #3219).
 */
final class NullsafeHelper
{
    public static function compileBranch(
        JIT $jit,
        Function_ $func,
        Block $branchBlock
    ): BasicBlock {
        return $jit->compileSubBlock($func, $branchBlock);
    }

    /**
     * i1: receiver is PHP null or uninitialized nullable typed property (#5220, ZEND_NULLSAFE).
     */
    public static function isReceiverNull(JIT $jit, Variable $receiver): \PHPLLVM\Value
    {
        $context = $jit->context;
        $builder = $context->builder;
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $obj = $context->helper->loadValue($receiver);

            return $builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $obj,
                $obj->typeOf()->constNull()
            );
        }
        if (Variable::TYPE_VALUE !== $receiver->type) {
            throw new \LogicException('nullsafe receiver must be object or value box');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $typeByte = $builder->load(
            $builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_NULL, false)
        );
        $nullableUninit = self::isNullableUninitializedProperty($context, $receiver, $valuePtr, $typeByte, $i8);
        if (null === $nullableUninit) {
            return $isNull;
        }

        return $builder->or($isNull, $nullableUninit);
    }

    private static function isNullableUninitializedProperty(
        Context $context,
        Variable $receiver,
        \PHPLLVM\Value $valuePtr,
        \PHPLLVM\Value $typeByte,
        \PHPLLVM\Type $i8
    ): ?\PHPLLVM\Value {
        if (null === $receiver->objectPropertyClassName || null === $receiver->objectPropertyName) {
            return null;
        }
        $object = $context->type->object;
        if (!$object instanceof Object_) {
            return null;
        }
        $resolved = $object->resolvePropertySlot($receiver->objectPropertyClassName, $receiver->objectPropertyName);
        if (null === $resolved) {
            return null;
        }
        [$classId, $slotIndex] = $resolved;
        if (!$object->propertySlotAllowsNull($classId, $slotIndex)) {
            return null;
        }
        $builder = $context->builder;

        return $builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false)
        );
    }
}
