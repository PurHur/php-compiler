<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for SimpleXML VM cast/json/empty hooks owned by ext/simplexml (#36204).
 *
 * lib/ must not import PHPCompiler\ext\simplexml; Module::init registers callables.
 *
 * php-src: ext/simplexml/sxe.c — sxe_object_cast_ex / get_properties_for / has_dimension.
 */
final class SimpleXmlVmRuntimeSupport
{
    /** @var null|callable(ObjectEntry): bool */
    private static $handles = null;

    /** @var null|callable(ObjectEntry): Variable */
    private static $exportZendArrayCast = null;

    /** @var null|callable(ObjectEntry): ?int */
    private static $tryCastObjectToInt = null;

    /** @var null|callable(ObjectEntry): ?float */
    private static $tryCastObjectToFloat = null;

    /** @var null|callable(ObjectEntry): bool */
    private static $handlesObjectCast = null;

    /** @var null|callable(ObjectEntry): bool */
    private static $objectIsTruthy = null;

    /** @var null|callable(ObjectEntry): bool */
    private static $isDimensionSubject = null;

    /** @var null|callable(ObjectEntry, Variable): bool */
    private static $dimensionIsEmpty = null;

    public static function clear(): void
    {
        self::$handles = null;
        self::$exportZendArrayCast = null;
        self::$tryCastObjectToInt = null;
        self::$tryCastObjectToFloat = null;
        self::$handlesObjectCast = null;
        self::$objectIsTruthy = null;
        self::$isDimensionSubject = null;
        self::$dimensionIsEmpty = null;
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

    /** @param callable(ObjectEntry): ?int $hook */
    public static function setTryCastObjectToInt(callable $hook): void
    {
        self::$tryCastObjectToInt = $hook;
    }

    /** @param callable(ObjectEntry): ?float $hook */
    public static function setTryCastObjectToFloat(callable $hook): void
    {
        self::$tryCastObjectToFloat = $hook;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setHandlesObjectCast(callable $hook): void
    {
        self::$handlesObjectCast = $hook;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setObjectIsTruthy(callable $hook): void
    {
        self::$objectIsTruthy = $hook;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setIsDimensionSubject(callable $hook): void
    {
        self::$isDimensionSubject = $hook;
    }

    /** @param callable(ObjectEntry, Variable): bool $hook */
    public static function setDimensionIsEmpty(callable $hook): void
    {
        self::$dimensionIsEmpty = $hook;
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

    public static function tryCastObjectToInt(ObjectEntry $object): ?int
    {
        if (null === self::$tryCastObjectToInt) {
            return null;
        }

        return (self::$tryCastObjectToInt)($object);
    }

    public static function tryCastObjectToFloat(ObjectEntry $object): ?float
    {
        if (null === self::$tryCastObjectToFloat) {
            return null;
        }

        return (self::$tryCastObjectToFloat)($object);
    }

    public static function handlesObjectCast(ObjectEntry $object): bool
    {
        return null !== self::$handlesObjectCast && (self::$handlesObjectCast)($object);
    }

    public static function objectIsTruthy(ObjectEntry $object): bool
    {
        if (null === self::$objectIsTruthy) {
            return true;
        }

        return (self::$objectIsTruthy)($object);
    }

    public static function isDimensionSubject(ObjectEntry $object): bool
    {
        return null !== self::$isDimensionSubject && (self::$isDimensionSubject)($object);
    }

    public static function dimensionIsEmpty(ObjectEntry $object, Variable $dim): bool
    {
        if (null === self::$dimensionIsEmpty) {
            return true;
        }

        return (self::$dimensionIsEmpty)($object, $dim);
    }
}
