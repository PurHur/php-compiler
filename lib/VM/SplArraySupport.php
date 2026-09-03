<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Static bridge to the Module-registered {@see SplArrayHandler} (#36204).
 *
 * Used from sites that lack a live Context (CastSupport / SplArrayCastJitHelper).
 */
final class SplArraySupport
{
    private static ?SplArrayHandler $handler = null;

    public static function setHandler(SplArrayHandler $handler): void
    {
        self::$handler = $handler;
    }

    public static function handler(): ?SplArrayHandler
    {
        return self::$handler;
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return self::$handler?->hasState($object) ?? false;
    }

    public static function hasArrayAsProps(ObjectEntry $object): bool
    {
        return self::$handler?->hasArrayAsProps($object) ?? false;
    }

    public static function dimensionIsSet(ObjectEntry $object, Variable $offset): bool
    {
        return self::$handler?->dimensionIsSet($object, $offset) ?? false;
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        return self::$handler?->offsetExists($object, $offset) ?? false;
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset, ?Frame $frame = null): Variable
    {
        if (null === self::$handler) {
            $null = new Variable();
            $null->null();

            return $null;
        }

        return self::$handler->offsetGet($object, $offset, $frame);
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        self::$handler?->offsetSet($object, $offset, $value);
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        self::$handler?->offsetUnset($object, $offset);
    }

    public static function getArrayCopy(ObjectEntry $object): ?HashTable
    {
        return self::$handler?->getArrayCopy($object);
    }

    /** @return array<string, Variable> */
    public static function collectJsonEncodeProperties(ObjectEntry $object): array
    {
        return self::$handler?->collectJsonEncodeProperties($object) ?? [];
    }

    public static function allowsForeachByRef(ObjectEntry $object): bool
    {
        return self::$handler?->allowsForeachByRef($object) ?? false;
    }

    public static function foreachCurrentByRef(ObjectEntry $object): ?Variable
    {
        return self::$handler?->foreachCurrentByRef($object);
    }

    public static function arrayCastDuplicate(ObjectEntry $object): ?HashTable
    {
        return self::$handler?->arrayCastDuplicate($object);
    }

    public static function isArrayObjectClass(string $className): bool
    {
        return self::$handler?->isArrayObjectClass($className) ?? false;
    }

    public static function allowsRecursiveArrayIteratorForeachByRef(ObjectEntry $object): bool
    {
        return self::$handler?->allowsRecursiveArrayIteratorForeachByRef($object) ?? false;
    }

    public static function recursiveArrayIteratorForeachCurrentByRef(ObjectEntry $object): ?Variable
    {
        return self::$handler?->recursiveArrayIteratorForeachCurrentByRef($object);
    }
}
