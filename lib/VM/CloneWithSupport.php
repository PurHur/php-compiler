<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.3+ clone-with readonly property reinit window (#7250).
 *
 * php-src unlocks IS_PROP_REINITABLE in zend_objects_clone_obj_with() before applying
 * the with property list, then clears after the block (Zend/zend_objects.c).
 */
final class CloneWithSupport
{
    /** @param list<string> $propNames */
    public static function beginReinit(ObjectEntry $object, array $propNames): void
    {
        foreach ($propNames as $name) {
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            $object->reinitableProperties[$name] = true;
        }
    }

    public static function endReinit(ObjectEntry $object): void
    {
        $object->reinitableProperties = [];
    }

    public static function consumeReinit(ObjectEntry $object, string $prop): bool
    {
        if (!isset($object->reinitableProperties[$prop])) {
            return false;
        }
        unset($object->reinitableProperties[$prop]);

        return true;
    }
}
