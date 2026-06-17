<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\ClassConstVisibility;
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
        $vis = $objectType->constVisibility($classId, $constName);
        if (MethodVisibility::isPublic($vis)) {
            return;
        }

        $context = $objectType->jitContext();
        $declaringClass = $objectType->classNameForId($classId);
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

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Function_);
        $entry = $context->builder->getInsertBlock();
        if (null === $entry || null !== $entry->getTerminator()) {
            return;
        }

        $failBlock = $fn->appendBasicBlock('class_const_vis_violation');
        $continueBlock = $fn->appendBasicBlock('class_const_vis_continue');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
            self::returnAfterPendingError($context, $fn);
        }

        $context->builder->positionAtEnd($continueBlock);
    }

    private static function returnAfterPendingError(Context $context, Function_ $fn): void
    {
        if (BasicBlockHelper::isVoidLlvmFunctionValue($fn)) {
            $context->builder->returnVoid();

            return;
        }
        $fnType = BasicBlockHelper::llvmFunctionSignatureType($fn);
        if (null !== $fnType) {
            $returnType = $fnType->getReturnType();
            if (Type::KIND_POINTER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constNull());

                return;
            }
            if (Type::KIND_INTEGER === $returnType->getKind()) {
                $context->builder->returnValue($returnType->constInt(0, false));

                return;
            }
            $structName = $context->getStringFromType($returnType);
            if ('__value__' === $structName) {
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );
                $context->builder->returnValue($context->builder->load($slot));

                return;
            }
        }
        $context->builder->returnVoid();
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
