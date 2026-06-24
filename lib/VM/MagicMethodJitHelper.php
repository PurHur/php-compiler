<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared magic-method compile-time guards for VM + JIT lowering (#10201, php-in-PHP).
 *
 * php-src: Zend/zend_object_handlers.c — zend_std_read_property / __get dispatch
 */
final class MagicMethodJitHelper
{
    /**
     * Whether a property read on $classId::$propertyName must route through __get (#4673).
     */
    public static function propertyReadUsesMagicGet(
        bool $hasGetMethod,
        bool $hasDeclaredProperty,
        bool $isPublicProperty,
        bool $visibilityDenied
    ): bool {
        if (!$hasGetMethod) {
            return false;
        }
        if (!$hasDeclaredProperty) {
            return true;
        }
        if ($isPublicProperty) {
            return false;
        }

        return $visibilityDenied;
    }
}
