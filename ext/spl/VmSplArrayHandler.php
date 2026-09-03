<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\SplArrayHandler;
use PHPCompiler\VM\Variable;

/**
 * Bridges SplArrayStorage + ArrayObject/RecursiveArrayIterator into lib/ (#36204).
 *
 * php-src: ext/spl/spl_array.c
 */
final class VmSplArrayHandler implements SplArrayHandler
{
    public function hasState(ObjectEntry $object): bool
    {
        return SplArrayStorage::hasState($object);
    }

    public function hasArrayAsProps(ObjectEntry $object): bool
    {
        return SplArrayStorage::hasArrayAsProps($object);
    }

    public function dimensionIsSet(ObjectEntry $object, Variable $offset): bool
    {
        return SplArrayStorage::dimensionIsSet($object, $offset);
    }

    public function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        return SplArrayStorage::offsetExists($object, $offset);
    }

    public function offsetGet(ObjectEntry $object, Variable $offset, ?Frame $frame = null): Variable
    {
        return SplArrayStorage::offsetGet($object, $offset, $frame);
    }

    public function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        SplArrayStorage::offsetSet($object, $offset, $value);
    }

    public function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        SplArrayStorage::offsetUnset($object, $offset);
    }

    public function getArrayCopy(ObjectEntry $object): HashTable
    {
        return SplArrayStorage::getArrayCopy($object);
    }

    public function collectJsonEncodeProperties(ObjectEntry $object): array
    {
        return SplArrayStorage::collectJsonEncodeProperties($object);
    }

    public function allowsForeachByRef(ObjectEntry $object): bool
    {
        return SplArrayStorage::allowsForeachByRef($object);
    }

    public function foreachCurrentByRef(ObjectEntry $object): Variable
    {
        return SplArrayStorage::foreachCurrentByRef($object);
    }

    public function arrayCastDuplicate(ObjectEntry $object): ?HashTable
    {
        return SplArrayStorage::arrayCastDuplicate($object);
    }

    public function isArrayObjectClass(string $className): bool
    {
        return ArrayObjectBuiltin::CLASS_LC === strtolower(ltrim($className, '\\'));
    }

    public function allowsRecursiveArrayIteratorForeachByRef(ObjectEntry $object): bool
    {
        return RecursiveArrayIteratorBuiltin::allowsForeachByRef($object);
    }

    public function recursiveArrayIteratorForeachCurrentByRef(ObjectEntry $object): Variable
    {
        return RecursiveArrayIteratorBuiltin::foreachCurrentByRef($object);
    }
}
