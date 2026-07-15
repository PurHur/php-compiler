<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\Operand;
use PHPCompiler\OpCode;
use PHPCompiler\VM\CloneSupport;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * TYPE_CLONE lowering — runtime object check matches Zend/zend_clones.c (#19097).
 */
final class CloneOperandHelper
{
    public static function compile(
        JIT $jit,
        Context $context,
        Block $block,
        OpCode $op
    ): void {
        $resultOp = $block->getOperand($op->arg1);
        $srcVar = $context->getVariableFromOp($block->getOperand($op->arg2));
        if (Variable::TYPE_OBJECT === $srcVar->type) {
            $srcObj = $context->helper->loadValue($srcVar);
            self::emitCloneFromObject($jit, $context, $block, $resultOp, $srcObj);

            return;
        }
        if (Variable::TYPE_VALUE === $srcVar->type) {
            self::compileFromValueBox($jit, $context, $block, $resultOp, $srcVar);

            return;
        }
        self::emitNonObjectError($jit, $context);
    }

    private static function compileFromValueBox(
        JIT $jit,
        Context $context,
        Block $block,
        Operand $resultOp,
        Variable $srcVar
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $srcVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $tag = 'c'.(string) spl_object_id($context);
        $objectBb = BasicBlockHelper::append($context, 'clone_vb_object_'.$tag);
        $errorBb = BasicBlockHelper::append($context, 'clone_vb_error_'.$tag);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBb, $errorBb);
        $context->builder->positionAtEnd($objectBb);
        $srcObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        self::emitCloneFromObject($jit, $context, $block, $resultOp, $srcObj);
        $context->builder->positionAtEnd($errorBb);
        self::emitNonObjectError($jit, $context);
    }

    private static function emitCloneFromObject(
        JIT $jit,
        Context $context,
        Block $block,
        Operand $resultOp,
        \PHPLLVM\Value $srcObj
    ): void {
        $cloned = $context->type->object->cloneObject($srcObj);
        $context->type->object->invokeCloneMagicIfPresent($block, $cloned);
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $cloned
        );
        $jit->assignOperand($resultOp, $objVar);
    }

    private static function emitNonObjectError(JIT $jit, Context $context): void
    {
        $message = CloneSupport::NON_OBJECT_ERROR_MESSAGE;
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);

            return;
        }
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->clearInsertionPosition();
    }
}
