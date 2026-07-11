<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * GC-backed weak reference registry (issue #3282).
 *
 * Tracks WeakReference target slots and WeakMap entries keyed by object id.
 * Cleared when {@see ObjectRegistry::release()} collects the referent.
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_weakrefs.c
 */
final class WeakRefRegistry
{
    /** @var array<int, list<array{slot: Variable, weakRefId: int}>> */
    private static array $refsByTargetId = [];

    /** @var array<int, list<array{mapId: int, key: string}>> */
    private static array $mapEntriesByTargetId = [];

    /** Referent ids cleared by unset / object release (#5089). */
    private static array $invalidatedTargetIds = [];

    public static function registerWeakRef(int $targetId, Variable $targetSlot, ObjectEntry $weakRef): void
    {
        self::$refsByTargetId[$targetId][] = [
            'slot' => $targetSlot,
            'weakRefId' => $weakRef->id,
        ];
    }

    public static function registerWeakMapEntry(int $targetId, ObjectEntry $map, string $key): void
    {
        self::unregisterWeakMapEntry($targetId, $map->id, $key);
        self::$mapEntriesByTargetId[$targetId][] = [
            'mapId' => $map->id,
            'key' => $key,
        ];
    }

    public static function unregisterWeakMapEntry(int $targetId, int $mapId, string $key): void
    {
        if (!isset(self::$mapEntriesByTargetId[$targetId])) {
            return;
        }
        self::$mapEntriesByTargetId[$targetId] = array_values(array_filter(
            self::$mapEntriesByTargetId[$targetId],
            static fn (array $entry): bool => $entry['mapId'] !== $mapId || $entry['key'] !== $key
        ));
        if ([] === self::$mapEntriesByTargetId[$targetId]) {
            unset(self::$mapEntriesByTargetId[$targetId]);
        }
    }

    public static function clearWeakRef(ObjectEntry $weakRef): void
    {
        foreach (self::$refsByTargetId as $targetId => $entries) {
            $filtered = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['weakRefId'] !== $weakRef->id
            ));
            if ([] === $filtered) {
                unset(self::$refsByTargetId[$targetId]);
            } else {
                self::$refsByTargetId[$targetId] = $filtered;
            }
        }
    }

    public static function clearWeakMap(ObjectEntry $map): void
    {
        foreach (self::$mapEntriesByTargetId as $targetId => $entries) {
            $filtered = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['mapId'] !== $map->id
            ));
            if ([] === $filtered) {
                unset(self::$mapEntriesByTargetId[$targetId]);
            } else {
                self::$mapEntriesByTargetId[$targetId] = $filtered;
            }
        }
    }

    /** VM entry for GC/object release weak-ref sweep (#6836). */
    public static function clearWeakRefsForObject(int $objectId): void
    {
        self::clearForObject($objectId);
    }

    public static function clearForObject(int $objectId): void
    {
        self::$invalidatedTargetIds[$objectId] = true;
        foreach (self::$refsByTargetId[$objectId] ?? [] as $entry) {
            $entry['slot']->clearWeakTarget();
        }
        unset(self::$refsByTargetId[$objectId]);

        foreach (self::$mapEntriesByTargetId[$objectId] ?? [] as $entry) {
            $map = ObjectRegistry::find($entry['mapId']);
            if (null === $map) {
                continue;
            }
            $ht = WeakRefSupport::mapTable($map);
            if (null === $ht) {
                continue;
            }
            $keyVar = new Variable(Variable::TYPE_STRING);
            $keyVar->string($entry['key']);
            $ht->offsetUnset($keyVar);
        }
        unset(self::$mapEntriesByTargetId[$objectId]);
    }

    public static function reset(): void
    {
        self::$refsByTargetId = [];
        self::$mapEntriesByTargetId = [];
        self::$invalidatedTargetIds = [];
    }

    public static function isReferentInvalidated(int $objectId): bool
    {
        return isset(self::$invalidatedTargetIds[$objectId]);
    }

    /** @return list<int> */
    public static function weakTargetIds(): array
    {
        return array_map('intval', array_keys(self::$refsByTargetId));
    }

    /** @return list<int> */
    public static function weakMapKeyTargetIds(): array
    {
        return array_map('intval', array_keys(self::$mapEntriesByTargetId));
    }

    public static function registeredMapTargetCount(): int
    {
        return \count(self::$mapEntriesByTargetId);
    }
}
