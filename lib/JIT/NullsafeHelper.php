<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\NullsafeRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;

/**
 * LLVM lowering helpers for ?-> nullsafe branch targets (issues #308, #3219, #10154).
 *
 * SSOT: {@see \PHPCompiler\VM\TypedPropertyCheck}, {@see \PHPCompiler\VM\NullsafeJitHelper}
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
        NullsafeRuntime::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $typeByte = $builder->load(
            $builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i1 = $context->getTypeFromString('int1');
        $nullableSlot = $i1->constInt(self::nullablePropertySlot($context, $receiver) ? 1 : 0, false);

        return NullsafeRuntime::callValueBoxShortCircuits($context, $typeByte, $nullableSlot);
    }

    private static function nullablePropertySlot(Context $context, Variable $receiver): bool
    {
        if (null === $receiver->objectPropertyClassName || null === $receiver->objectPropertyName) {
            return false;
        }
        $object = $context->type->object;
        if (!$object instanceof Object_) {
            return false;
        }
        $resolved = $object->resolvePropertySlot($receiver->objectPropertyClassName, $receiver->objectPropertyName);
        if (null === $resolved) {
            return false;
        }
        [$classId, $slotIndex] = $resolved;

        return $object->propertySlotAllowsNull($classId, $slotIndex);
    }
}
