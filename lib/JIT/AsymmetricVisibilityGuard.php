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
 * Compile-time asymmetric set-visibility checks before JIT property stores (#3165, #4029, #4639).
 *
 * php-src: Zend/zend_object_handlers.c zend_check_property_access
 */
final class AsymmetricVisibilityGuard
{
    /**
     * @return bool true when the store was blocked (caller must skip propertyStore)
     */
    public static function emitBeforePropertyStore(
        Context $context,
        \PHPCompiler\JIT $jit,
        Variable $lvalue,
        ?Block $enclosingBlock
    ): bool {
        return self::emitBeforePropertySetOp($context, $jit, $lvalue, $enclosingBlock, 'modify');
    }

    /**
     * unset() follows set-visibility (zend_object_handlers.c, #23338).
     *
     * @return bool true when unset was blocked (caller must skip propertyStore)
     */
    public static function emitBeforePropertyUnset(
        Context $context,
        \PHPCompiler\JIT $jit,
        Variable $lvalue,
        ?Block $enclosingBlock
    ): bool {
        return self::emitBeforePropertySetOp($context, $jit, $lvalue, $enclosingBlock, 'unset');
    }

    /**
     * @param 'modify'|'unset' $verb
     *
     * @return bool true when the operation was blocked
     */
    private static function emitBeforePropertySetOp(
        Context $context,
        \PHPCompiler\JIT $jit,
        Variable $lvalue,
        ?Block $enclosingBlock,
        string $verb
    ): bool {
        if (null === $lvalue->objectPropertySlot || null === $lvalue->objectPropertyName) {
            return false;
        }
        if (null !== $context->jitPropertyHookRawProperty) {
            return false;
        }
        if (self::isConstructBlock($enclosingBlock)) {
            return false;
        }

        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        $declaringClass = $lvalue->objectPropertyClassName ?? '';
        if ('' === $declaringClass) {
            return false;
        }

        $propName = $lvalue->objectPropertyName;
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        $classId = $objectType->lookup($declaringClass);
        // Readonly ordinary writes use ReadonlyClassGuard; aviz applies on clone-with reinit (#29186).
        if ($objectType->isPropertyReadonly($classId, $propName)
            || $objectType->isReadonlyClass($classId)) {
            return false;
        }
        $readVis = $objectType->propertyVisibility($classId, $propName);
        $effectiveRead = PropertyVisibility::effectiveGetVisibility(
            $readVis,
            $objectType->propertyGetVisibility($classId, $propName)
        );
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $objectType->propertySetVisibility($classId, $propName)
        );
        if ($setVis === MethodVisibility::mask($effectiveRead)) {
            return false;
        }

        $callerLc = self::callerClassLc($context, $enclosingBlock);
        $callerDisplay = self::callerClassDisplay($context, $enclosingBlock);
        try {
            if ('unset' === $verb) {
                PropertyVisibility::assertUnsettable(
                    $setVis,
                    $callerLc,
                    $declaringLc,
                    $declaringClass,
                    $propName,
                    static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                    MethodVisibility::mask($effectiveRead),
                    $objectType->propertyAsymmetricExplicitRead($classId, $propName),
                    $callerDisplay
                );
            } else {
                PropertyVisibility::assertWritable(
                    $setVis,
                    $callerLc,
                    $declaringLc,
                    $declaringClass,
                    $propName,
                    static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                    MethodVisibility::mask($effectiveRead),
                    $objectType->propertyAsymmetricExplicitRead($classId, $propName),
                    $callerDisplay
                );
            }
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());

            return true;
        }

        return false;
    }

    /**
     * @return bool true when the store was blocked (caller must skip staticPropertyStore)
     */
    public static function emitBeforeStaticPropertyStore(
        Context $context,
        \PHPCompiler\JIT $jit,
        Variable $lvalue,
        ?Block $enclosingBlock
    ): bool {
        if (null === $lvalue->staticPropertyGlobal) {
            return false;
        }
        $classLc = $lvalue->staticPropertyHookClassLc ?? '';
        $propName = $lvalue->objectPropertyName ?? '';
        if ('' === $classLc || '' === $propName) {
            return false;
        }
        if (self::isConstructBlock($enclosingBlock)) {
            return false;
        }

        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        $classId = $objectType->lookup($classLc);
        $meta = $objectType->staticPropertyVisibilityMeta($classId, $propName);
        if (null === $meta) {
            return false;
        }
        $effectiveRead = PropertyVisibility::effectiveGetVisibility(
            $meta['visibility'],
            $meta['getVisibility'] ?? 0
        );
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $meta['visibility'],
            $meta['setVisibility'] ?? 0
        );
        if ($setVis === MethodVisibility::mask($effectiveRead)) {
            return false;
        }
        $declaringClass = $meta['declaringClassName'];
        $declaringLc = strtolower(ltrim($declaringClass, '\\'));
        // Fetch CE in the Error (self/static → child), matching zend_std_set_static_property (#29524).
        $fetchClassDisplay = $objectType->classNameForId($classId);
        $callerLc = self::callerClassLc($context, $enclosingBlock);
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $fetchClassDisplay,
                $propName,
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent),
                MethodVisibility::mask($effectiveRead),
                $meta['asymmetricExplicitRead'] ?? false,
                self::callerClassDisplay($context, $enclosingBlock)
            );
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());

            return true;
        }

        return false;
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

    /** Original casing for zend_asymmetric_visibility_property_modification_error (#26298). */
    private static function callerClassDisplay(Context $context, ?Block $enclosingBlock): ?string
    {
        if (null !== $enclosingBlock?->func?->class) {
            return ltrim($enclosingBlock->func->class->value, '\\');
        }
        if ('' !== $context->scope->className) {
            return ltrim($context->scope->className, '\\');
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

        $failBlock = $fn->appendBasicBlock('asymmetric_violation');
        $continueBlock = $fn->appendBasicBlock('asymmetric_guard_continue');

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

    private static function isConstructBlock(?Block $block): bool
    {
        if (null === $block || null === $block->func) {
            return false;
        }
        $name = strtolower($block->func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }
}
