<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\PropertyVisibility;

/**
 * Typed-property initialization probes (Zend zend_object_handlers.c / ReflectionProperty::isInitialized, #6513).
 */
final class PropertyInit
{
    /**
     * Whether a declared instance property on $object has been initialized.
     *
     * @throws \Error when $propName is not a declared instance property
     * @throws \LogicException when visibility denies access (converted to catchable Error by caller)
     */
    public static function isInstancePropertyInitialized(
        \PHPCompiler\VM $vm,
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): bool {
        $meta = VmReflection::findClassProperty($object->class, $propName, $vm->context);
        if (null === $meta) {
            throw new \Error(sprintf(
                'Property %s::$%s does not exist',
                $object->class->name,
                $propName
            ));
        }

        $declaringDisplay = $vm->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        PropertyVisibility::assertAccessible(
            $meta->visibility,
            self::callerClassLcFromFrame($frame),
            $meta->declaringClassLc,
            $declaringDisplay,
            $meta->name,
            strtolower($object->class->name),
            fn (string $classLc, string $ancestorLc): bool => self::isSameOrSubclassOf($vm->context, $classLc, $ancestorLc),
            $meta->getVisibility
        );

        if (!$object->hasProperty($meta->name)) {
            return false;
        }

        $slot = $object->getProperty($meta->name)->resolveIndirect();
        if ($slot->isUndefined() || TypedPropertyCheck::isUninitialized($slot)) {
            return false;
        }

        return true;
    }

    private static function callerClassLcFromFrame(Frame $frame): ?string
    {
        if (null !== $frame->block && null !== $frame->block->func && null !== $frame->block->func->class) {
            return strtolower($frame->block->func->class->value);
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        return null;
    }

    private static function isSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            if (!isset($ctx->classes[$current])) {
                return false;
            }
            $parentLc = $ctx->classes[$current]->parentLc;
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }
}
