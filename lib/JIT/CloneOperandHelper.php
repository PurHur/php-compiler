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
 *
 * When the clone source is `$this` (or a typed object with a known class), restrict
 * the class-id dispatch to that class + subclasses. Emitting a case for every
 * registered class stalls Slim/Nyholm AOT for minutes on `Uri::withUserInfo` (#36382).
 */
final class CloneOperandHelper
{
    public static function compile(
        JIT $jit,
        Context $context,
        Block $block,
        OpCode $op
    ): void {
        Progress::noteFunction('clone_begin');
        $resultOp = $block->getOperand($op->arg1);
        $srcOp = $block->getOperand($op->arg2);
        $srcVar = $context->getVariableFromOp($srcOp);
        $restrictIds = self::resolveRestrictClassIds($context, $block, $srcOp, $srcVar);
        if (Variable::TYPE_OBJECT === $srcVar->type) {
            $srcObj = $context->helper->loadValue($srcVar);
            self::emitCloneFromObject($jit, $context, $block, $resultOp, $srcObj, $restrictIds);
            Progress::noteFunction('clone_done');

            return;
        }
        if (Variable::TYPE_VALUE === $srcVar->type) {
            self::compileFromValueBox($jit, $context, $block, $resultOp, $srcVar, $restrictIds);
            Progress::noteFunction('clone_done');

            return;
        }
        self::emitNonObjectError($jit, $context);
        Progress::noteFunction('clone_done');
    }

    /**
     * @return list<int>|null
     */
    private static function resolveRestrictClassIds(
        Context $context,
        Block $block,
        Operand $srcOp,
        Variable $srcVar
    ): ?array {
        $className = null;
        if (is_string($srcVar->classUserType) && '' !== $srcVar->classUserType) {
            $className = $srcVar->classUserType;
        } elseif (
            null !== $srcOp->type
            && is_string($srcOp->type->userType ?? null)
            && '' !== ($srcOp->type->userType ?? '')
        ) {
            $className = (string) $srcOp->type->userType;
        } elseif (self::isThisOperand($context, $block, $srcOp, $srcVar)) {
            $func = $block->func ?? null;
            if (null !== $func && null !== ($func->class ?? null)) {
                $cv = $func->class->value ?? null;
                if (is_string($cv) && '' !== $cv) {
                    $className = $cv;
                }
            }
        }
        if (null === $className || '' === $className) {
            return null;
        }
        if (0 === strcasecmp($className, 'object') || 0 === strcasecmp($className, 'mixed')) {
            return null;
        }
        $ids = $context->type->object->classIdsInstanceOf($className);
        if ([] === $ids) {
            return null;
        }
        Progress::noteFunction('clone_restrict:'.strtolower(ltrim($className, '\\')).':'.count($ids));

        return $ids;
    }

    private static function isThisOperand(
        Context $context,
        Block $block,
        Operand $srcOp,
        Variable $srcVar
    ): bool {
        if (null !== $context->implicitThisArgument && $srcVar === $context->implicitThisArgument) {
            return true;
        }
        // php-cfg names the receiver `$this` on instance methods.
        if (
            $srcOp instanceof Operand\Variable
            && is_string($srcOp->name->value ?? null)
            && 'this' === strtolower((string) $srcOp->name->value)
        ) {
            return true;
        }
        // First ARG_RECV of an instance method is `$this`.
        if (null !== $block->func && null !== ($block->func->class ?? null) && [] !== ($block->func->params ?? [])) {
            $first = $block->func->params[0]->result ?? null;
            if ($first instanceof Operand && $first === $srcOp) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int>|null $restrictIds
     */
    private static function compileFromValueBox(
        JIT $jit,
        Context $context,
        Block $block,
        Operand $resultOp,
        Variable $srcVar,
        ?array $restrictIds
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $srcVar);
        $srcObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        self::emitCloneFromObject($jit, $context, $block, $resultOp, $srcObj, $restrictIds);
    }

    /**
     * @param list<int>|null $restrictIds
     */
    private static function emitCloneFromObject(
        JIT $jit,
        Context $context,
        Block $block,
        Operand $resultOp,
        Value $srcObj,
        ?array $restrictIds
    ): void {
        self::emitDenyCloneGuard($jit, $context, $srcObj, $restrictIds);
        // zend_lazy_object_clone — initialize pending lazy before shallow copy (#29171).
        LazyObjectHelper::emitEnsureInitialized($context, $srcObj);
        $cloned = $context->type->object->cloneObject($srcObj, $restrictIds);
        $context->type->object->invokeCloneMagicIfPresent($block, $cloned, $restrictIds);
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
     *
     * @param list<int>|null $restrictIds
     */
    private static function emitDenyCloneGuard(JIT $jit, Context $context, Value $srcObj, ?array $restrictIds): void
    {
        $denied = $context->type->object->uncloneableClassIdsForGuard($restrictIds);
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
