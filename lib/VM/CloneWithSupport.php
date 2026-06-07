<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.3+ clone-with readonly reinit window (Zend/zend_objects.c IS_PROP_REINITABLE, #7250).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects.c zend_objects_clone_obj_with()
 */
final class CloneWithSupport
{
    /**
     * Mark readonly properties that may be written once during clone-with.
     *
     * @param list<string> $propertyNames
     */
    public static function markReinitable(ObjectEntry $object, array $propertyNames): void
    {
        foreach ($propertyNames as $name) {
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $object->reinitableReadonlyProps[strtolower($name)] = true;
        }
    }

    /** Allow one readonly write when the property was marked reinitable; consumes the slot. */
    public static function tryConsumeReinitable(ObjectEntry $object, string $propertyName): bool
    {
        $key = strtolower($propertyName);
        if (!isset($object->reinitableReadonlyProps[$key])) {
            return false;
        }
        unset($object->reinitableReadonlyProps[$key]);

        return true;
    }

    public static function clearReinitable(ObjectEntry $object): void
    {
        $object->reinitableReadonlyProps = [];
    }
}
