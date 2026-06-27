<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ObjectEntry;

/**
 * Backing pathname storage for SplFileInfo / SplFileObject (php-src ext/spl/spl_directory.c).
 */
final class SplFileInfoStorage
{
    /** @var array<int, string> */
    private static array $store = [];

    public static function init(ObjectEntry $object, string $pathname): void
    {
        self::$store[$object->id] = $pathname;
    }

    public static function pathname(ObjectEntry $object): string
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplFileInfo object state missing');
        }

        return self::$store[$object->id];
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }
}
