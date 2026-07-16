<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared helpers for WeakReference / WeakMap (issues #1366, #3282).
 *
 * Targets use non-refcounted object slots; {@see WeakRefRegistry} clears them when
 * the referent is released (unset / GC) like Zend zend_weakrefs.c.
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
        $key = $key->resolveIndirect();
        $copy = new Variable();
        if (Variable::TYPE_OBJECT === $key->type) {
            // GC mark list only — must not retain a strong ref (zend_weakrefs.c, #14103).
            $copy->weakObject($key->toObject());
        } else {
            $copy->copyFrom($key);
        }
        $slot->toArray()->append($copy);
    }

    public static function targetObjectId(Variable $key): int
    {
        $key = $key->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($key)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($key);
            $caseName = EnumCaseSupport::enumCaseNameForVariable($key);
            if (null !== $enumClass && '' !== $caseName) {
                return self::stableEnumCaseTargetId($enumClass->name, $caseName);
            }
        }

        return self::requireWeakMapKey($key)->toObject()->id;
    }

    public static function requireObject(Variable $var, string $label): Variable
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError("{$label} must be of type object");
        }

        return $var;
    }

    /**
     * Weak referent — Zend treats enum cases as weak-referenceable objects (#5681, zend_weakrefs.c).
     */
    public static function requireWeakReferent(Variable $var, string $label): Variable
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            return $var;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::receiverForInstanceMethod($var);
        }

        throw new \TypeError("{$label} must be of type object");
    }

    public static function requireWeakReferentObject(Variable $var, string $label): ObjectEntry
    {
        return self::requireWeakReferent($var, $label)->toObject();
    }

    /** Zend zend_weakrefs.c — WeakMap offset key must be object (#5433, #5681). */
    public static function requireWeakMapKey(Variable $var): Variable
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            return $var;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::receiverForInstanceMethod($var);
        }

        throw new \TypeError('WeakMap key must be an object');
    }

    /**
     * Stable hash-table storage key for enum case array offsets, or null when not an enum case (#9871).
     */
    public static function objectKeyIfEnumCase(Variable $key): ?string
    {
        $key = $key->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($key)) {
            return self::objectKey($key);
        }
        if (Variable::TYPE_OBJECT === $key->type && EnumCaseSupport::isEnumCase($key->toObject())) {
            return self::objectKey($key);
        }

        return null;
    }

    /** Materialize int/string/enum-case keys from hash-table storage (#9871, zend_hash.c). */
    public static function materializeArrayKey(Variable $key): Variable
    {
        if (Variable::TYPE_STRING === $key->type) {
            $resolved = self::resolveMapKeyVariable($key->toString());
            if (null !== $resolved) {
                $out = new Variable();
                $out->copyFrom($resolved);

                return $out;
            }
        }
        $out = new Variable();
        $out->copyFrom($key);

        return $out;
    }

    public static function objectKey(Variable $key): string
    {
        $key = $key->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($key)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($key);
            $caseName = EnumCaseSupport::enumCaseNameForVariable($key);
            if (null !== $enumClass && '' !== $caseName) {
                return self::stableEnumCaseMapKey($enumClass->name, $caseName);
            }
        }
        $object = self::requireWeakMapKey($key)->toObject();
        if (EnumCaseSupport::isEnumCase($object)) {
            return self::stableEnumCaseMapKey($object->class->name, $object->enumCaseName ?? '');
        }

        return 'o:'.$object->id;
    }

    /** Resolve WeakMap backing string key (o:<id> or e:<enum>:<case>) to a live object Variable, or null if stale (#4434, #5629). */
    public static function resolveMapKeyVariable(string $storedKey): ?Variable
    {
        if (str_starts_with($storedKey, 'e:')) {
            return self::resolveStableEnumCaseMapKey($storedKey);
        }
        if (!str_starts_with($storedKey, 'o:')) {
            return null;
        }
        $rest = substr($storedKey, 2);
        if ('' === $rest || !ctype_digit($rest)) {
            return null;
        }
        $id = (int) $rest;
        if (!ObjectRegistry::isRegistered($id)) {
            return null;
        }
        $entry = ObjectRegistry::find($id);
        if (null === $entry) {
            return null;
        }
        $key = new Variable(Variable::TYPE_OBJECT);
        $key->object($entry);

        return $key;
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
        if (EnumCaseSupport::isEnumCaseVariable($target)) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            $object = $target->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return true;
            }
            $objectId = $object->id;
            if (WeakRefRegistry::isReferentInvalidated($objectId)) {
                return false;
            }
            if (!ObjectRegistry::isRegistered($objectId)) {
                return false;
            }
            if ($object->refCount <= 0) {
                return false;
            }
            // Orphan internal refcount with no live scope binding — treat as collected (#13474).
            if (!self::hasStrongScopeBinding($objectId)) {
                return false;
            }

            return true;
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
        // iterateKeyed() materializes o:<id> keys to objects via materializeArrayKey (#9871).
        // Stale referents can still resolve while lacking a strong scope binding (#19369) — treat
        // both string and materialized object/enum keys, and drop anything !isTargetAlive().
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar, $value] = $pair;
            $storedKey = null;
            $objectId = null;
            if (
                Variable::TYPE_OBJECT === $storedKeyVar->type
                || EnumCaseSupport::isEnumCaseVariable($storedKeyVar)
            ) {
                if (self::isTargetAlive($storedKeyVar)) {
                    continue;
                }
                $storedKey = self::objectKey($storedKeyVar);
                $objectId = self::targetObjectId($storedKeyVar);
            } elseif (Variable::TYPE_STRING === $storedKeyVar->type) {
                $storedKey = $storedKeyVar->toString();
                if (str_starts_with($storedKey, 'e:')) {
                    continue;
                }
                if (!str_starts_with($storedKey, 'o:')) {
                    continue;
                }
                $objectId = (int) substr($storedKey, 2);
                $resolved = self::resolveMapKeyVariable($storedKey);
                if (null !== $resolved && self::isTargetAlive($resolved)) {
                    continue;
                }
            } else {
                continue;
            }
            $keyVar->string($storedKey);
            $ht->offsetUnset($keyVar);
            if (null !== $objectId) {
                WeakRefRegistry::unregisterWeakMapEntry($objectId, $weakMap->id, $storedKey);
            }
        }
    }

    /** Count live WeakMap entries after purge (Countable / count(), #19369). */
    public static function countLiveMapEntries(ObjectEntry $weakMap): int
    {
        self::purgeStaleMapEntries($weakMap);
        $ht = self::mapTable($weakMap);
        if (null === $ht) {
            return 0;
        }
        $n = 0;
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar] = $pair;
            if (
                Variable::TYPE_OBJECT === $storedKeyVar->type
                || EnumCaseSupport::isEnumCaseVariable($storedKeyVar)
            ) {
                if (self::isTargetAlive($storedKeyVar)) {
                    ++$n;
                }
                continue;
            }
            if (Variable::TYPE_STRING !== $storedKeyVar->type) {
                continue;
            }
            $resolved = self::resolveMapKeyVariable($storedKeyVar->toString());
            if (null !== $resolved && self::isTargetAlive($resolved)) {
                ++$n;
            }
        }

        return $n;
    }

    public static function copyAliveTarget(Variable $dst, Variable $target): void
    {
        $target = $target->resolveIndirect();
        if (!self::isTargetAlive($target)) {
            $dst->null();

            return;
        }
        if (Variable::TYPE_OBJECT === $target->type && EnumCaseSupport::isEnumCase($target->toObject())) {
            $object = $target->toObject();
            $canonical = BackedEnum::canonicalCaseVariable($object->class, $object->enumCaseName ?? '');
            if (null !== $canonical && EnumCaseSupport::isEnumCaseVariable($canonical)) {
                $dst->copyFrom($canonical);

                return;
            }
        }
        $dst->copyFrom($target);
    }

    /** True when a named local, dynamic local, or global still holds a strong ref (#13474, #14103, #14132). */
    public static function hasStrongScopeBinding(int $objectId): bool
    {
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            return false;
        }
        $found = false;
        $vm->visitNamedStrongRefRoots(static function (Variable $var) use ($objectId, &$found): void {
            if ($found) {
                return;
            }
            $resolved = $var->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                return;
            }
            try {
                if ($resolved->toObject()->id === $objectId) {
                    $found = true;
                }
            } catch (\LogicException) {
            }
        });

        return $found;
    }

    /** Stable WeakMap hash key for enum case operands — identity is class+name, not ephemeral object id (#5629). */
    public static function stableEnumCaseMapKey(string $enumName, string $caseName): string
    {
        return 'e:'.strtolower(ltrim($enumName, '\\')).':'.$caseName;
    }

    /** Synthetic registry id for immortal enum case weak-map targets (negative — never ObjectRegistry ids). */
    public static function stableEnumCaseTargetId(string $enumName, string $caseName): int
    {
        return -(int) (crc32(strtolower(ltrim($enumName, '\\'))."\0".$caseName) & 0x7FFFFFFF);
    }

    private static function resolveStableEnumCaseMapKey(string $storedKey): ?Variable
    {
        $parts = explode(':', $storedKey, 3);
        if (3 !== \count($parts) || 'e' !== $parts[0] || '' === $parts[1] || '' === $parts[2]) {
            return null;
        }
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            return null;
        }
        $enumClass = $vm->context->classes[$parts[1]] ?? null;
        if (null === $enumClass || !$enumClass->isEnum) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enumClass, $parts[2]);
        if (null === $canonical) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($canonical)) {
            return EnumCaseSupport::receiverForInstanceMethod($canonical);
        }

        return null;
    }
}
