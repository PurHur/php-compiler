<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for WeakReference / WeakMap VM stubs (issue #1366).
 *
 * Targets use indirect slots so unset() on the last strong variable clears get().
 * This is not cycle-collecting GC weak references.
 */
final class WeakRefSupport
{
    public const TARGET_PROPERTY = '__weak_target';
    public const MAP_PROPERTY = '__weak_map';

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

    public static function mapTable(ObjectEntry $weakMap): HashTable
    {
        $slot = $weakMap->getProperty(self::MAP_PROPERTY)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $slot->type) {
            throw new \LogicException('WeakMap backing store is missing in this compiler build');
        }

        return $slot->toArray();
    }

    public static function initMapBacking(ObjectEntry $weakMap): void
    {
        $slot = $weakMap->getProperty(self::MAP_PROPERTY);
        $slot->newArray();
    }

    public static function isTargetAlive(Variable $target): bool
    {
        $target = $target->resolveIndirect();

        return !$target->isUndefined() && Variable::TYPE_NULL !== $target->type;
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
