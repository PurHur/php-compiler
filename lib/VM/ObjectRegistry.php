<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Tracks live VM object instances for {@see CycleCollector} (issue #3113).
 */
final class ObjectRegistry
{
    /** @var array<int, ObjectEntry> */
    private static array $instances = [];

    public static function register(ObjectEntry $object): void
    {
        self::$instances[$object->id] = $object;
    }

    public static function unregister(int $id): void
    {
        unset(self::$instances[$id]);
    }

    public static function isRegistered(int $id): bool
    {
        return isset(self::$instances[$id]);
    }

    public static function find(int $id): ?ObjectEntry
    {
        return self::$instances[$id] ?? null;
    }

    /** @return array<int, ObjectEntry> */
    public static function snapshot(): array
    {
        return self::$instances;
    }

    public static function release(ObjectEntry $object): void
    {
        WeakRefRegistry::clearForObject($object->id);
        if (WeakRefSupport::isWeakReference($object)) {
            WeakRefRegistry::clearWeakRef($object);
        }
        if (WeakRefSupport::isWeakMap($object)) {
            WeakRefRegistry::clearWeakMap($object);
        }
        $object->destroyForGc();
        self::unregister($object->id);
    }

    public static function reset(): void
    {
        self::$instances = [];
        WeakRefRegistry::reset();
    }
}
