<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * JIT: clone on non-object throws catchable Error (#19097, Zend/zend_clones.c).
 */
final class CloneNonObjectJitGuard
{
    public const MESSAGE = '__clone method called on non-object';

    /**
     * @return ?Variable cloned object rvalue, or null when error path was emitted
     */
    public static function lowerClone(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $op
    ): ?Variable {
        $context = $jit->context;
        $srcVar = $context->getVariableFromOp($block->getOperand($op->arg2));

        if (Variable::TYPE_OBJECT === $srcVar->type) {
            $srcObj = $context->helper->loadValue($srcVar);

            return self::finishClone($context, $block, $srcObj);
        }

        if (Variable::TYPE_VALUE === $srcVar->type) {
            return self::lowerCloneFromValueBox($jit, $block, $srcVar);
        }

        self::emitCloneNonObjectError($context, $jit);

        return null;
    }

    private static function lowerCloneFromValueBox(
        \PHPCompiler\JIT $jit,
        Block $block,
        Variable $srcVar
    ): ?Variable {
        $context = $jit->context;
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $srcVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );

        $fn = $context->builder->getInsertBlock()?->getParent();
        if (!$fn instanceof Function_) {
            self::emitCloneNonObjectError($context, $jit);

            return null;
        }

        $okBlock = $fn->appendBasicBlock('clone_value_object');
        $errBlock = $fn->appendBasicBlock('clone_value_non_object');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitCloneNonObjectError($context, $jit);

        $context->builder->positionAtEnd($okBlock);
        $srcObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );

        return self::finishClone($context, $block, $srcObj);
    }

    private static function finishClone(Context $context, Block $block, Value $srcObj): Variable
    {
        $cloned = $context->type->object->cloneObject($srcObj);
        $context->type->object->invokeCloneMagicIfPresent($block, $cloned);

        return new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $cloned
        );
    }

    public static function emitCloneNonObjectError(Context $context, ?\PHPCompiler\JIT $jit): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, self::MESSAGE);
        } else {
            ErrorRaise::emitRaise($context, self::MESSAGE);
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();
        }
    }
}
