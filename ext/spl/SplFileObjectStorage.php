<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ObjectEntry;

/** Stream handle storage for SplFileObject / SplTempFileObject (php-src ext/spl/spl_directory.c). */
final class SplFileObjectStorage
{
    /** @var array<int, int> */
    private static array $handles = [];

    public static function setHandle(ObjectEntry $object, int $handle): void
    {
        self::$handles[$object->id] = $handle;
    }

    public static function handle(ObjectEntry $object): int
    {
        if (!isset(self::$handles[$object->id])) {
            throw new \LogicException('SplFileObject stream handle missing');
        }

        return self::$handles[$object->id];
    }

    public static function hasHandle(ObjectEntry $object): bool
    {
        return isset(self::$handles[$object->id]);
    }
}
