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
        $copy = new Variable();
        $copy->copyFrom($key);
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
        foreach ($ht->iterateKeyed() as $pair) {
            [$storedKeyVar, $value] = $pair;
            if (Variable::TYPE_STRING !== $storedKeyVar->type) {
                continue;
            }
            $storedKey = $storedKeyVar->toString();
            if (str_starts_with($storedKey, 'e:')) {
                continue;
            }
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

    /** True when a run-stack slot or global still holds a strong ref to $objectId (#13474). */
    public static function hasStrongScopeBinding(int $objectId): bool
    {
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            return false;
        }
        $ctx = $vm->context;
        foreach ($ctx->runStackFrames() as $frame) {
            if (self::scopeReferencesObject($frame->scope, $objectId)) {
                return true;
            }
        }
        $found = false;
        $ctx->visitGlobalVariables(function (Variable $global) use ($objectId, &$found): void {
            if ($found) {
                return;
            }
            $resolved = $global->resolveIndirect();
            if (Variable::TYPE_OBJECT === $resolved->type && $resolved->toObject()->id === $objectId) {
                $found = true;
            }
        });
        if ($found) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, Variable> $scope
     */
    private static function scopeReferencesObject(array $scope, int $objectId): bool
    {
        foreach ($scope as $slot) {
            $resolved = $slot->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                continue;
            }
            try {
                if ($resolved->toObject()->id === $objectId) {
                    return true;
                }
            } catch (\LogicException) {
            }
        }

        return false;
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
