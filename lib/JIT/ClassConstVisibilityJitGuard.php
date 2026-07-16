<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ClassConstVisibility;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_;

/**
 * Class constant visibility before JIT fetch (issue #4651, #6664; Zend zend_constants.c).
 */
final class ClassConstVisibilityJitGuard
{
    public static function emitBeforeFetch(
        Object_ $objectType,
        \PHPCompiler\JIT $jit,
        Block $block,
        int $classId,
        string $constName
    ): void {
        $holdingId = $objectType->resolveClassConstHoldingId($classId, strtolower($constName));
        if (null === $holdingId) {
            // Missing / private-on-parent: classConstFetch throws; caller emits runtime Error (#19615).
            return;
        }
        $vis = $objectType->constVisibility($holdingId, $constName);
        if (MethodVisibility::isPublic($vis)) {
            return;
        }

        $context = $objectType->jitContext();
        $declaringClass = $objectType->classNameForId($holdingId);
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $callerLc = self::callerClassLc($context, $block);
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $callerLc,
                $declaringLc,
                $declaringClass,
                $constName,
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent)
            );
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());
        }
    }

    /**
     * Runtime Error for Undefined constant Class::CONST (private parent / missing) (#19615).
     *
     * Catchable Error-object IR fails AOT module verify in several try/catch shapes.
     * JIT keeps catchable throws; AOT sets pending Error and aborts (Zend message).
     */
    public static function emitUndefinedConstantError(
        Context $context,
        \PHPCompiler\JIT $jit,
        string $message
    ): void {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        $canCatch = [] !== $context->tryCatch->handlerStack
            && null !== $insert
            && Builtin::LOAD_TYPE_STANDALONE !== $context->loadType;
        if ($canCatch) {
            $fn = $insert->getParent();
            assert($fn instanceof Function_);
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            $cont = $fn->appendBasicBlock('after_class_const_undef');
            $context->builder->positionAtEnd($cont);

            return;
        }
        if (null === $insert) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }
        $fn = $insert->getParent();
        assert($fn instanceof Function_);
        ErrorRaise::emitRaise($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
        }
        self::returnAfterPendingError($context, $fn);
        $cont = $fn->appendBasicBlock('after_class_const_undef_ret');
        $context->builder->positionAtEnd($cont);
    }

    private static function callerClassLc(Context $context, ?Block $enclosingBlock): ?string
    {
        if (null !== $enclosingBlock?->func?->class) {
            $funcClassLc = strtolower(ltrim($enclosingBlock->func->class->value, '\\'));
            if ($context->type->object->hasDeclaredClass($funcClassLc)
                && $context->type->object->isTraitClass($funcClassLc)) {
                return $funcClassLc;
            }
        }
        $declaringLc = null;
        if (null !== $enclosingBlock?->func?->class) {
            $declaringLc = strtolower(ltrim($enclosingBlock->func->class->value, '\\'));
        } elseif ('' !== $context->scope->className) {
            $declaringLc = strtolower(ltrim($context->scope->className, '\\'));
        }
        if (null === $declaringLc) {
            return null;
        }
        $methodLc = strtolower((string) ($enclosingBlock?->func?->name ?? ''));
        if ('' !== $methodLc && $context->type->object->hasDeclaredClass($declaringLc)) {
            $traitLc = $context->type->object->traitMethodSource(
                $context->type->object->lookup($declaringLc),
                $methodLc
            );
            if (null !== $traitLc) {
                return $traitLc;
            }
        }

        return $declaringLc;
    }

    private static function emitViolation(Context $context, \PHPCompiler\JIT $jit, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);

        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $insert || null !== $insert->getTerminator()) {
            return;
        }
        $fn = $insert->getParent();
        assert($fn instanceof Function_);

        $failBlock = $fn->appendBasicBlock('class_const_vis_violation');
        $continueBlock = $fn->appendBasicBlock('class_const_vis_continue');

        $context->builder->positionAtEnd($insert);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        $useCatchable = [] !== $context->tryCatch->handlerStack
            && Builtin::LOAD_TYPE_STANDALONE !== $context->loadType;
        if ($useCatchable) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            $sealed = BasicBlockHelper::tryGetInsertBlock($context);
            if (null !== $sealed && null === $sealed->getTerminator()) {
                self::returnAfterPendingError($context, $fn);
            }
        } else {
            ErrorRaise::emitRaise($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
            }
            self::returnAfterPendingError($context, $fn);
        }

        $context->builder->positionAtEnd($continueBlock);
    }

    /** Typed return after pending Error — never ret void from __value__ methods (#19615). */
    private static function returnAfterPendingError(Context $context, Function_ $fn): void
    {
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        if (null !== $fnType) {
            $returnType = $fnType->getReturnType();
            $kind = $returnType->getKind();
            if (Type::KIND_VOID === $kind) {
                $context->builder->returnVoid();

                return;
            }
            if (Type::KIND_INTEGER === $kind) {
                $context->builder->returnValue($returnType->constInt(0, false));

                return;
            }
            if (Type::KIND_DOUBLE === $kind || Type::KIND_FLOAT === $kind) {
                $context->builder->returnValue($returnType->constReal(0.0));

                return;
            }
            $context->builder->returnValue($returnType->constNull());

            return;
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $context->builder->returnValue($context->builder->load($slot));
    }

    private static function isSubclassOf(Object_ $object, string $childLc, string $parentLc): bool
    {
        $current = $childLc;
        for ($depth = 0; $depth < 64; ++$depth) {
            if ($current === $parentLc) {
                return true;
            }
            $parent = $object->parentClassLc($current);
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }
}
