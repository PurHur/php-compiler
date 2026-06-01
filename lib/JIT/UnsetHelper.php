<?php

declare(strict_types=1);

/**
 * JIT lowering for unset() on properties and array offsets (issue #1224).
 */

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

final class UnsetHelper
{
    public static function compileOffset(
        Context $context,
        Block $block,
        OpCode $op
    ): void {
        $containerOp = $block->getOperand($op->arg2);
        $dimOp = $block->getOperand($op->arg3);
        $container = $context->getVariableFromOp($containerOp);
        $dim = $context->getVariableFromOp($dimOp);
        if (Variable::TYPE_OBJECT === $container->type) {
            if (ArrayAccessHelper::tryCompileOffsetUnset($context, $container, $dim, $containerOp)) {
                return;
            }
            self::compilePropertyUnset($context, $block, $containerOp, $dimOp);

            return;
        }
        if (Variable::TYPE_HASHTABLE === $container->type || Variable::TYPE_VALUE === $container->type) {
            HashTableHelper::offsetUnset($context, $container, $dim);

            return;
        }
        throw new \LogicException('unset() offset only supports arrays and objects in this compiler build');
    }

    private static function compilePropertyUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp
    ): void {
        assert($containerOp->type->type === Type::TYPE_OBJECT);
        $declaringClass = $containerOp->type->userType;
        if (null === $declaringClass && null !== $block->func && null !== $block->func->class) {
            $declaringClass = $block->func->class->value;
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $declaringClass = '' !== $context->scope->className
                ? $context->scope->className
                : 'object';
        }
        $receiver = self::loadPropertyReceiver($context, $containerOp);
        $null = new Variable(
            $context,
            Variable::TYPE_NULL,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__value__*')->constNull()
        );
        $null->isNullConstant = true;
        if ($dimOp instanceof Literal) {
            $prop = $context->type->object->propertyFetch($receiver, $declaringClass, $dimOp->value);
            if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
                ReadonlyClassGuard::emitBeforePropertyStore(
                    $context,
                    $prop,
                    $context->jitEnclosingBlock,
                    'unset'
                );
                $context->type->object->propertyStore(
                    $prop->objectPropertySlot,
                    $null,
                    $prop->objectPropertyType
                );
            }

            return;
        }
        $nameVar = $context->getVariableFromOp($dimOp);
        $prop = $context->type->object->propertyFetchDynamic($receiver, $declaringClass, $nameVar);
        if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
            ReadonlyClassGuard::emitBeforePropertyStore(
                $context,
                $prop,
                $context->jitEnclosingBlock,
                'unset'
            );
            $context->type->object->propertyStore(
                $prop->objectPropertySlot,
                $null,
                $prop->objectPropertyType
            );
        }
    }

    private static function loadPropertyReceiver(Context $context, Operand $objOp): \PHPLLVM\Value
    {
        $var = $context->getVariableFromOp($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $var)
            );
        }

        throw new \LogicException(
            'Property unset receiver must be object or object-valued property, got '
            . Variable::getStringType($var->type)
        );
    }
}
