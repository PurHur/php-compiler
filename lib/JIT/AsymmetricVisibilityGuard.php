<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;

/**
 * Compile-time asymmetric set-visibility checks before JIT property stores (#3165, #4029).
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
        $readVis = $objectType->propertyVisibility($classId, $propName);
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $objectType->propertySetVisibility($classId, $propName)
        );
        if ($setVis === MethodVisibility::mask($readVis)) {
            return false;
        }

        $callerLc = '' !== $context->scope->className
            ? strtolower(ltrim($context->scope->className, '\\'))
            : null;
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringClass,
                $propName,
                static fn (string $child, string $parent): bool => self::isSubclassOf($objectType, $child, $parent)
            );
        } catch (\LogicException $e) {
            self::emitViolation($context, $jit, $e->getMessage());

            return true;
        }

        return false;
    }

    private static function emitViolation(Context $context, \PHPCompiler\JIT $jit, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);

        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $failBlock = $fn->appendBasicBlock('asymmetric_violation');
        $exitBlock = $fn->appendBasicBlock('asymmetric_guard_exit');

        $context->builder->positionAtEnd($entry);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($failBlock);
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
        } else {
            $msgLen = $context->constantFromInteger(strlen($message), 'size_t');
            $msgCStr = $context->builder->pointerCast(
                $context->constantFromString($message),
                $context->getTypeFromString('int8*')
            );
            $context->builder->call(
                $context->lookupFunction('__compiler_jit_raise_error'),
                $msgCStr,
                $msgLen
            );
            // returnVoid after pending raise — same as ReadonlyClassGuard (#3149, #4020).
            $context->builder->returnVoid();
        }

        $context->builder->positionAtEnd($exitBlock);
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
