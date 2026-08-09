<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_;

/**
 * Instance property read visibility before JIT fetch (#8760, #8751; Zend zend_object_handlers.c).
 */
final class InstancePropertyVisibilityJitGuard
{
    public static function emitBeforeFetch(
        Object_ $objectType,
        \PHPCompiler\JIT $jit,
        Block $block,
        int $classId,
        string $propName,
        string $receiverClassName
    ): void {
        $meta = $objectType->instancePropertyVisibilityMeta($classId, $propName);
        if (null === $meta) {
            return;
        }
        $getVisibility = $meta['getVisibility'] ?? 0;
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return;
        }

        $context = $objectType->jitContext();
        $declaringClass = $meta['declaringClassName'];
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $callerLc = self::callerClassLc($context, $block);
        $receiverLc = strtolower(ltrim($receiverClassName, '\\'));
        if (PropertyVisibility::isParentPrivatePropertyInvisibleFromChildScope(
            $meta['visibility'],
            $callerLc,
            $declaringLc,
            static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
            $getVisibility,
            $receiverLc
        )) {
            return;
        }
        try {
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $callerLc,
                $declaringLc,
                $declaringClass,
                $propName,
                $receiverLc,
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                $getVisibility
            );
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());
        }
    }

    public static function isInvisibleParentPrivateFetch(
        Object_ $objectType,
        int $classId,
        string $propName,
        ?Block $enclosingBlock
    ): bool {
        $meta = $objectType->instancePropertyVisibilityMeta($classId, $propName);
        if (null === $meta) {
            return false;
        }
        $getVisibility = $meta['getVisibility'] ?? 0;
        $declaringLc = strtolower(ltrim($meta['declaringClassName'], '\\'));
        $callerLc = self::callerClassLc($objectType->jitContext(), $enclosingBlock);
        $receiverLc = self::classIdToLc($objectType, $classId);

        return PropertyVisibility::isParentPrivatePropertyInvisibleFromChildScope(
            $meta['visibility'],
            $callerLc,
            $declaringLc,
            static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
            $getVisibility,
            $receiverLc
        );
    }

    /**
     * ?? / ??= BP_VAR_IS: inaccessible declared props are silent-null (zend_std_has_property; #29503).
     * Returns true when the dest was written and the normal fetch must be skipped.
     */
    public static function trySilentNullForIsModeFetch(
        Object_ $objectType,
        \PHPCompiler\JIT $jit,
        Block $block,
        int $classId,
        string $propName,
        string $receiverClassName,
        \PHPCfg\Operand $destOp
    ): bool {
        $meta = $objectType->instancePropertyVisibilityMeta($classId, $propName);
        if (null === $meta) {
            return false;
        }
        $getVisibility = $meta['getVisibility'] ?? 0;
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return false;
        }

        $declaringClass = $meta['declaringClassName'];
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $callerLc = self::callerClassLc($objectType->jitContext(), $block);
        $receiverLc = strtolower(ltrim($receiverClassName, '\\'));
        if (PropertyVisibility::isParentPrivatePropertyInvisibleFromChildScope(
            $meta['visibility'],
            $callerLc,
            $declaringLc,
            static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
            $getVisibility,
            $receiverLc
        )) {
            NonObjectPropertyFetchHelper::lowerNullPropertyDest($objectType->jitContext(), $destOp);

            return true;
        }
        try {
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $callerLc,
                $declaringLc,
                $declaringClass,
                $propName,
                $receiverLc,
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                $getVisibility
            );
        } catch (\LogicException $e) {
            NonObjectPropertyFetchHelper::lowerNullPropertyDest($objectType->jitContext(), $destOp);

            return true;
        }

        return false;
    }

    private static function classIdToLc(Object_ $objectType, int $classId): ?string
    {
        try {
            $name = $objectType->classNameForId($classId);
        } catch (\Throwable $e) {
            return null;
        }
        if ('' === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private static function callerClassLc(Context $context, ?Block $enclosingBlock): ?string
    {
        if (null !== $enclosingBlock?->func?->class) {
            return strtolower(ltrim($enclosingBlock->func->class->value, '\\'));
        }
        if ('' !== $context->scope->className) {
            return strtolower(ltrim($context->scope->className, '\\'));
        }

        return null;
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

        $failBlock = $fn->appendBasicBlock('instance_prop_vis_violation');
        $continueBlock = $fn->appendBasicBlock('instance_prop_vis_continue');

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
