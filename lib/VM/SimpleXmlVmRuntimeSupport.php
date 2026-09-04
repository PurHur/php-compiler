<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for SimpleXML VM cast/json hooks owned by ext/simplexml (#36204).
 *
 * lib/ must not import PHPCompiler\ext\simplexml; Module::init registers callables.
 *
 * php-src: ext/simplexml/sxe.c — sxe_object_cast_ex / get_properties_for.
 */
final class SimpleXmlVmRuntimeSupport
{
    /** @var null|callable(ObjectEntry): bool */
    private static $handles = null;

    /** @var null|callable(ObjectEntry): Variable */
    private static $exportZendArrayCast = null;

    public static function clear(): void
    {
        self::$handles = null;
        self::$exportZendArrayCast = null;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setHandles(callable $hook): void
    {
        self::$handles = $hook;
    }

    /** @param callable(ObjectEntry): Variable $hook */
    public static function setExportZendArrayCast(callable $hook): void
    {
        self::$exportZendArrayCast = $hook;
    }

    public static function handles(ObjectEntry $object): bool
    {
        return null !== self::$handles && (self::$handles)($object);
    }

    public static function exportZendArrayCast(ObjectEntry $object): ?Variable
    {
        if (null === self::$exportZendArrayCast) {
            return null;
        }

        return (self::$exportZendArrayCast)($object);
    }
}
