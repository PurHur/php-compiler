<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\ObjectEntry;

/** Iterator cursor for SimpleXMLIterator (php-src ext/simplexml/sxe.c; #6694). */
final class SimpleXmlIteratorStorage
{
    /** @var array<int, int> */
    private static array $indexes = [];

    public static function reset(): void
    {
        self::$indexes = [];
    }

    public static function init(ObjectEntry $iterator): void
    {
        self::$indexes[$iterator->id] = 0;
    }

    public static function index(ObjectEntry $iterator): int
    {
        return self::$indexes[$iterator->id] ?? 0;
    }

    public static function setIndex(ObjectEntry $iterator, int $index): void
    {
        self::$indexes[$iterator->id] = $index;
    }

    public static function rewind(ObjectEntry $iterator): void
    {
        self::$indexes[$iterator->id] = 0;
    }
}
