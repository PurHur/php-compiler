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

    /** @return array<int, ObjectEntry> */
    public static function snapshot(): array
    {
        return self::$instances;
    }

    public static function release(ObjectEntry $object): void
    {
        $object->destroyForGc();
        self::unregister($object->id);
    }
}
