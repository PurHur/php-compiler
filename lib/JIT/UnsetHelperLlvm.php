<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmUnset;
use PHPLLVM\Builder;

/**
 * LLVM lowering bodies for unset() — delegates semantic guards to {@see VmUnset} (#10238).
 */
final class UnsetHelperLlvm
{
    public static function compileOffset(
        Context $context,
        Block $block,
        OpCode $op,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $containerOp = $block->getOperand($op->arg2);
        $dimOp = $block->getOperand($op->arg3);
        $container = $context->getVariableFromOp($containerOp);
        $dim = $context->getVariableFromOp($dimOp);
        if (Variable::TYPE_OBJECT === $container->type) {
            if (ArrayAccessHelper::tryCompileOffsetUnset($context, $container, $dim, $containerOp)) {
                return;
            }
            self::compilePropertyUnset($context, $block, $containerOp, $dimOp, $jit);

            return;
        }
        if (Variable::TYPE_HASHTABLE === $container->type) {
            HashTableHelper::offsetUnset($context, $container, $dim);

            return;
        }
        if (VmUnset::isScalarJitContainer($container)) {
            self::emitScalarUnsetDimError($context, $container);

            return;
        }
        if (Variable::TYPE_VALUE === $container->type) {
            self::compileValueBoxOffsetUnset($context, $block, $containerOp, $dimOp, $container, $dim, $jit);

            return;
        }
        throw new \LogicException('unset() offset only supports arrays and objects in this compiler build');
    }

    private static function emitScalarUnsetDimError(Context $context, Variable $container): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::scalarUnsetDimErrorMessage($container->type));
    }

    private static function compileValueBoxOffsetUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp,
        Variable $container,
        Variable $dim,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'u'.(string) spl_object_id($context);

        $arrayBb = BasicBlockHelper::append($context, 'unset_dim_vb_array_'.$tag);
        $objectBb = BasicBlockHelper::append($context, 'unset_dim_vb_object_'.$tag);
        $stringBb = BasicBlockHelper::append($context, 'unset_dim_vb_string_'.$tag);
        $scalarBb = BasicBlockHelper::append($context, 'unset_dim_vb_scalar_'.$tag);
        $afterArray = BasicBlockHelper::append($context, 'unset_dim_vb_after_array_'.$tag);
        $afterObject = BasicBlockHelper::append($context, 'unset_dim_vb_after_object_'.$tag);
        $afterString = BasicBlockHelper::append($context, 'unset_dim_vb_after_string_'.$tag);

        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $context->builder->branchIf($isArray, $arrayBb, $afterArray);

        $context->builder->positionAtEnd($arrayBb);
        HashTableHelper::offsetUnset($context, $container, $dim);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($afterArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBb, $afterObject);

        $context->builder->positionAtEnd($objectBb);
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__object__*')->constNull()
        );
        $objVar->value = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        if (ArrayAccessHelper::tryCompileOffsetUnset($context, $objVar, $dim, $containerOp)) {
            $context->builder->returnVoid();
        } else {
            self::compilePropertyUnset($context, $block, $containerOp, $dimOp, $jit);
            $context->builder->returnVoid();
        }

        $context->builder->positionAtEnd($afterObject);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $afterString);

        $context->builder->positionAtEnd($stringBb);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_STRING_OFFSET);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($afterString);
        $context->builder->branch($scalarBb);

        $context->builder->positionAtEnd($scalarBb);
        ErrorRaise::emitRaise($context, VmUnset::ERROR_NON_ARRAY);
        $context->builder->returnVoid();
    }

    private static function compilePropertyUnset(
        Context $context,
        Block $block,
        Operand $containerOp,
        Operand $dimOp,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        $operandUserType = Type::TYPE_OBJECT === $containerOp->type->type
            ? $containerOp->type->userType
            : null;
        $blockClassName = null !== $block->func && null !== $block->func->class
            ? $block->func->class->value
            : null;
        $declaringClass = VmUnset::resolveDeclaringClass(
            $operandUserType,
            $blockClassName,
            $context->scope->className
        );
        $receiver = self::loadPropertyReceiver($context, $containerOp);
        $null = new Variable(
            $context,
            Variable::TYPE_NULL,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__value__*')->constNull()
        );
        $null->isNullConstant = true;
        if ($dimOp instanceof Literal) {
            if (PropertyHookDispatch::emitVirtualHookUnsetGuard(
                $context,
                $declaringClass,
                $dimOp->value,
                $jit
            )) {
                return;
            }
            $prop = $context->type->object->propertyFetch($receiver, $declaringClass, $dimOp->value);
            if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
                DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                    $context,
                    $prop,
                    $context->jitEnclosingBlock,
                    'unset'
                );
                ReadonlyClassGuard::emitBeforePropertyStore(
                    $context,
                    $prop,
                    $context->jitEnclosingBlock,
                    'unset'
                );
                ReadonlyClassGuard::emitStoreUnlessPending(
                    $context,
                    static function () use ($context, $prop, $null): void {
                        $context->type->object->propertyStore(
                            $prop->objectPropertySlot,
                            $null,
                            $prop->objectPropertyType
                        );
                    }
                );
            }

            return;
        }
        $nameVar = $context->getVariableFromOp($dimOp);
        $prop = $context->type->object->propertyFetchDynamic($receiver, $declaringClass, $nameVar);
        if (null !== $prop->objectPropertySlot && null !== $prop->objectPropertyType) {
            DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                $context,
                $prop,
                $context->jitEnclosingBlock,
                'unset'
            );
            ReadonlyClassGuard::emitBeforePropertyStore(
                $context,
                $prop,
                $context->jitEnclosingBlock,
                'unset'
            );
            ReadonlyClassGuard::emitStoreUnlessPending(
                $context,
                static function () use ($context, $prop, $null): void {
                    $context->type->object->propertyStore(
                        $prop->objectPropertySlot,
                        $null,
                        $prop->objectPropertyType
                    );
                }
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
            .Variable::getStringType($var->type)
        );
    }
}
