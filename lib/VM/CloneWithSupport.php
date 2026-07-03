<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.3+ readonly property reinit during clone-with and __clone (#7250, #15365).
 *
 * php-src unlocks IS_PROP_REINITABLE in zend_objects_clone_obj_with() before applying
 * the with property list, then clears after the block (Zend/zend_objects.c).
 * Readonly amendments allow one reinit per readonly property during __clone (zend_readonly.c),
 * but not on readonly classes — all instance properties stay immutable in __clone() (#15409).
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
        \PHPCompiler\ext\standard\CloneWithJitHelper::registerVmCloneReinit($object->id);
    }

    public static function endReinit(ObjectEntry $object): void
    {
        $object->reinitableProperties = [];
        \PHPCompiler\ext\standard\CloneWithJitHelper::unregisterVmCloneReinit($object->id);
    }

    /**
     * @param callable(ObjectEntry, string): ?string $isReadonlyProperty
     *     returns declaring class name when $propName is readonly, else null
     */
    public static function beginCloneMagicReinit(ObjectEntry $object, callable $isReadonlyProperty): void
    {
        if ($object->class->readonly) {
            return;
        }
        $names = [];
        foreach (array_keys($object->propertiesWithNames()) as $propName) {
            if (null !== $isReadonlyProperty($object, $propName)) {
                $names[] = $propName;
            }
        }
        self::beginReinit($object, $names);
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
