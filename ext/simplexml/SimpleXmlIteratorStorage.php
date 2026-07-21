<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\ObjectEntry;

/**
 * Iterator cursor for SimpleXMLElement / SimpleXMLIterator
 * (php-src ext/simplexml/simplexml.c — sxe->iter.data UNDEF until rewind; #6694, #21887).
 */
final class SimpleXmlIteratorStorage
{
    /** @var array<int, int> object id → child index; absent means uninitialized (Zend UNDEF). */
    private static array $indexes = [];

    public static function reset(): void
    {
        self::$indexes = [];
    }

    /** Mark cursor uninitialized (php-src iter.data UNDEF until rewind). */
    public static function init(ObjectEntry $iterator): void
    {
        unset(self::$indexes[$iterator->id]);
    }

    /** @return int Child index, or -1 when uninitialized / exhausted marker consumers treat as invalid */
    public static function index(ObjectEntry $iterator): int
    {
        return self::$indexes[$iterator->id] ?? -1;
    }

    public static function setIndex(ObjectEntry $iterator, int $index): void
    {
        self::$indexes[$iterator->id] = $index;
    }

    public static function rewind(ObjectEntry $iterator): void
    {
        self::$indexes[$iterator->id] = 0;
    }

    /** Whether rewind()/next() has established a current position (php-src !Z_ISUNDEF(iter.data)). */
    public static function isInitialized(ObjectEntry $iterator): bool
    {
        return \array_key_exists($iterator->id, self::$indexes);
    }
}
