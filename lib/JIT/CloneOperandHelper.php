<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\LazyObjectHelper;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPCompiler\VM\CloneSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_;

/**
 * TYPE_CLONE lowering — runtime object check matches Zend/zend_clones.c (#19097).
 *
 * Lazy objects: Zend/zend_lazy_objects.c zend_lazy_object_clone initializes
 * before clone (#29171).
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
        self::emitDenyCloneGuard($jit, $context, $srcObj);
        // zend_lazy_object_clone — initialize pending lazy before shallow copy (#29171).
        LazyObjectHelper::emitEnsureInitialized($context, $srcObj);
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

    /**
     * Reject clone when handlers.clone_obj is NULL (Exception/Error, WeakReference; #25870, #25962).
     */
    private static function emitDenyCloneGuard(JIT $jit, Context $context, Value $srcObj): void
    {
        $denied = $context->type->object->uncloneableClassIdsForGuard();
        if ([] === $denied) {
            return;
        }

        $fn = $context->builder->getInsertBlock()?->getParent();
        if (!$fn instanceof Function_) {
            return;
        }
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $classId = $context->type->object->readRuntimeClassId($srcObj);
        $continue = $fn->appendBasicBlock('clone_deny_ok');
        $checkBlock = $entry;
        foreach ($denied as $i => [$id, $className]) {
            $context->builder->positionAtEnd($checkBlock);
            $fail = $fn->appendBasicBlock('clone_deny_'.$id);
            $next = $i + 1 < count($denied)
                ? $fn->appendBasicBlock('clone_deny_try_'.($i + 1))
                : $continue;
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $context->builder->branchIf($isId, $fail, $next);
            $context->builder->positionAtEnd($fail);
            self::emitUncloneableError($jit, $context, $className);
            $checkBlock = $next;
        }
        $context->builder->positionAtEnd($continue);
    }

    private static function emitUncloneableError(JIT $jit, Context $context, string $className): void
    {
        $message = CloneSupport::uncloneableObjectErrorMessage($className);
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
