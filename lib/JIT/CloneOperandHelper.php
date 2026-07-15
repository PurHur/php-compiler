<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPCompiler\VM\CloneSupport;

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
        $srcObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        self::emitCloneFromObject($jit, $context, $block, $resultOp, $srcObj);
    }

    private static function emitCloneFromObject(
        JIT $jit,
        Context $context,
        Block $block,
        Operand $resultOp,
        Value $srcObj
    ): void {
        $cloned = $context->type->object->cloneObject($srcObj);
        $context->type->object->invokeCloneMagicIfPresent($block, $cloned);
        $objVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $cloned
        );
        $jit->assignOperandForced($resultOp, $objVar);
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
