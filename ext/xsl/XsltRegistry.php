<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\VM\ObjectEntry;

/** Per-instance host XSLTProcessor state (#3665). */
final class XsltRegistry
{
    /** @var array<int, \XSLTProcessor> */
    private static array $processors = [];

    public static function attach(ObjectEntry $entry, \XSLTProcessor $processor): void
    {
        self::$processors[$entry->id] = $processor;
    }

    public static function has(ObjectEntry $entry): bool
    {
        return isset(self::$processors[$entry->id]);
    }

    public static function processor(ObjectEntry $entry): \XSLTProcessor
    {
        if (!isset(self::$processors[$entry->id])) {
            throw new \LogicException('XSLTProcessor has no registered processor state in this compiler build');
        }

        return self::$processors[$entry->id];
    }
}
