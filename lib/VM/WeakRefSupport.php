<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for WeakReference / WeakMap (issues #1366, #3282).
 *
 * Targets use indirect slots so unset() on the last strong variable clears get().
 * {@see WeakRefRegistry} clears slots when the referent is cycle-collected.
 */
final class WeakRefSupport
{
    public const TARGET_PROPERTY = '__weak_target';
    public const MAP_PROPERTY = '__weak_map';
    public const MAP_KEYS_PROPERTY = '__weak_map_keys';

    public static function isWeakReference(ObjectEntry $object): bool
    {
        return 0 === strcasecmp($object->class->name, 'WeakReference');
    }

    public static function isWeakMap(ObjectEntry $object): bool
    {
        return 0 === strcasecmp($object->class->name, 'WeakMap');
    }

    public static function shouldSkipGcMark(ObjectEntry $object, string $propertyName): bool
    {
        if (self::isWeakReference($object) && self::TARGET_PROPERTY === $propertyName) {
            return true;
        }

        return self::isWeakMap($object)
            && (self::MAP_KEYS_PROPERTY === $propertyName);
    }

    public static function trackWeakMapKey(ObjectEntry $weakMap, Variable $key): void
    {
        $slot = $weakMap->getProperty(self::MAP_KEYS_PROPERTY);
        if (Variable::TYPE_ARRAY !== $slot->resolveIndirect()->type) {
            $slot->newArray();
        }
        $copy = new Variable();
        $copy->copyFrom($key);
        $slot->toArray()->append($copy);
    }

    public static function targetObjectId(Variable $key): int
    {
        return self::requireObject($key, 'WeakMap key')->toObject()->id;
    }

    public static function requireObject(Variable $var, string $label): Variable
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type object");
        }

        return $var;
    }

    public static function objectKey(Variable $key): string
    {
        $key = self::requireObject($key, 'WeakMap key');

        return 'o:'.$key->toObject()->id;
    }

    public static function targetSlot(ObjectEntry $weakRef): Variable
    {
        return $weakRef->getProperty(self::TARGET_PROPERTY);
    }

    public static function mapTable(ObjectEntry $weakMap): ?HashTable
    {
        $slot = $weakMap->getProperty(self::MAP_PROPERTY)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $slot->type) {
            return null;
        }

        return $slot->toArray();
    }

    public static function initMapBacking(ObjectEntry $weakMap): void
    {
        $slot = $weakMap->getProperty(self::MAP_PROPERTY);
        $slot->newArray();
        $weakMap->getProperty(self::MAP_KEYS_PROPERTY)->newArray();
    }

    public static function isTargetAlive(Variable $target): bool
    {
        $target = $target->resolveIndirect();
        if ($target->isUndefined() || Variable::TYPE_NULL === $target->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            return ObjectRegistry::isRegistered($target->toObject()->id);
        }

        return true;
    }

    public static function purgeStaleMapEntries(ObjectEntry $weakMap): void
    {
        $ht = self::mapTable($weakMap);
        if (null === $ht) {
            return;
        }
        $keyVar = new Variable(Variable::TYPE_STRING);
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar, $value] = $pair;
            if (Variable::TYPE_STRING !== $storedKeyVar->type) {
                continue;
            }
            $storedKey = $storedKeyVar->toString();
            if (!str_starts_with($storedKey, 'o:')) {
                continue;
            }
            $objectId = (int) substr($storedKey, 2);
            if (!ObjectRegistry::isRegistered($objectId)) {
                $keyVar->string($storedKey);
                $ht->offsetUnset($keyVar);
                WeakRefRegistry::unregisterWeakMapEntry($objectId, $weakMap->id, $storedKey);
            }
        }
    }

    public static function copyAliveTarget(Variable $dst, Variable $target): void
    {
        $target = $target->resolveIndirect();
        if (!self::isTargetAlive($target)) {
            $dst->null();

            return;
        }
        $dst->copyFrom($target);
    }
}
