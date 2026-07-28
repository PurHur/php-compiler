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
        $resolved = $slot->resolveIndirect();
        if (
            Variable::TYPE_ARRAY !== $resolved->type
            || !isset($resolved->array)
            || $resolved->array->isDestroyed()
        ) {
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
        $slot = $weakMap->getProperty(self::MAP_PROPERTY);
        $resolved = $slot->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            return null;
        }
        $ht = $resolved->toArray();
        if ($ht->isDestroyed()) {
            self::reinitMapBackingTable($weakMap);

            return $weakMap->getProperty(self::MAP_PROPERTY)->resolveIndirect()->toArray();
        }

        return $ht;
    }

    /**
     * True when a HashTable is still owned by a live WeakMap instance (#24270, zend_weakrefs.c).
     *
     * GC must not destroy internal backing arrays while the WeakMap object survives — empty maps
     * after weak-key collection still accept offsetSet().
     */
    public static function isInternalTableForLiveWeakMap(HashTable $table): bool
    {
        $tableId = \spl_object_id($table);
        foreach (ObjectRegistry::snapshot() as $object) {
            if (!self::isWeakMap($object)) {
                continue;
            }
            foreach ([self::MAP_PROPERTY, self::MAP_KEYS_PROPERTY] as $propName) {
                $slot = $object->getProperty($propName)->resolveIndirect();
                if (
                    Variable::TYPE_ARRAY === $slot->type
                    && isset($slot->array)
                    && \spl_object_id($slot->array) === $tableId
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function reinitMapBackingTable(ObjectEntry $weakMap): void
    {
        $weakMap->getProperty(self::MAP_PROPERTY)->newArray();
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
        // Collect keys first — bucketKeyToVariable() materializes o:<id> to TYPE_OBJECT
        // when the referent is still registered (#19369), so we must not rely on string
        // keys alone while mutating the HT.
        $staleKeys = [];
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar] = $pair;
            $storedKeyVar = $storedKeyVar->resolveIndirect();
            $storedKey = self::mapStorageKeyIfStale($storedKeyVar);
            if (null !== $storedKey) {
                $staleKeys[$storedKey] = true;
            }
        }
        foreach (array_keys($staleKeys) as $storedKey) {
            $keyVar->string($storedKey);
            $ht->offsetUnset($keyVar);
            if (str_starts_with($storedKey, 'o:')) {
                $objectId = (int) substr($storedKey, 2);
                WeakRefRegistry::unregisterWeakMapEntry($objectId, $weakMap->id, $storedKey);
            }
        }
    }

    /**
     * Return backing HT string key (o:<id>) when the WeakMap entry's referent is dead, else null.
     *
     * HashTable::bucketKeyToVariable() materializes live o:<id> keys to TYPE_OBJECT (#19369).
     */
    public static function mapStorageKeyIfStale(Variable $storedKeyVar): ?string
    {
        $storedKeyVar = $storedKeyVar->resolveIndirect();
        if (Variable::TYPE_OBJECT === $storedKeyVar->type) {
            $object = $storedKeyVar->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                return null;
            }
            $objectId = $object->id;
            $storedKey = self::objectKey($storedKeyVar);
            if (
                !ObjectRegistry::isRegistered($objectId)
                || WeakRefRegistry::isReferentInvalidated($objectId)
                || !self::hasStrongScopeBinding($objectId)
                || !self::isTargetAlive($storedKeyVar)
            ) {
                return $storedKey;
            }

            return null;
        }
        if (Variable::TYPE_STRING !== $storedKeyVar->type) {
            return null;
        }
        $storedKey = $storedKeyVar->toString();
        if (str_starts_with($storedKey, 'e:')) {
            return null;
        }
        if (!str_starts_with($storedKey, 'o:')) {
            return null;
        }
        $objectId = (int) substr($storedKey, 2);
        if (
            !ObjectRegistry::isRegistered($objectId)
            || WeakRefRegistry::isReferentInvalidated($objectId)
            || !self::hasStrongScopeBinding($objectId)
        ) {
            return $storedKey;
        }

        return null;
    }

    /** True when a WeakMap HT key (materialized object or o:/e: string) still refers to a live entry. */
    public static function isLiveMapKey(Variable $storedKeyVar): bool
    {
        $storedKeyVar = $storedKeyVar->resolveIndirect();
        if (Variable::TYPE_OBJECT === $storedKeyVar->type) {
            return self::isTargetAlive($storedKeyVar);
        }
        if (EnumCaseSupport::isEnumCaseVariable($storedKeyVar)) {
            return true;
        }
        if (Variable::TYPE_STRING !== $storedKeyVar->type) {
            return false;
        }
        $storedKey = $storedKeyVar->toString();
        if (str_starts_with($storedKey, 'e:')) {
            return null !== self::resolveMapKeyVariable($storedKey);
        }
        if (!str_starts_with($storedKey, 'o:')) {
            return false;
        }
        $objectId = (int) substr($storedKey, 2);

        return ObjectRegistry::isRegistered($objectId)
            && !WeakRefRegistry::isReferentInvalidated($objectId)
            && self::hasStrongScopeBinding($objectId);
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
