<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Extension-owned SPL ArrayObject / ArrayIterator backing storage (#36204).
 *
 * lib/ must not import PHPCompiler\ext\spl; Module::init registers an implementation.
 *
 * php-src: ext/spl/spl_array.c
 */
interface SplArrayHandler
{
    public function hasState(ObjectEntry $object): bool;

    public function hasArrayAsProps(ObjectEntry $object): bool;

    public function dimensionIsSet(ObjectEntry $object, Variable $offset): bool;

    public function offsetExists(ObjectEntry $object, Variable $offset): bool;

    public function offsetGet(ObjectEntry $object, Variable $offset, ?Frame $frame = null): Variable;

    public function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void;

    public function offsetUnset(ObjectEntry $object, Variable $offset): void;

    public function getArrayCopy(ObjectEntry $object): HashTable;

    /** @return array<string, Variable> */
    public function collectJsonEncodeProperties(ObjectEntry $object): array;

    public function allowsForeachByRef(ObjectEntry $object): bool;

    public function foreachCurrentByRef(ObjectEntry $object): Variable;

    public function arrayCastDuplicate(ObjectEntry $object): ?HashTable;

    public function isArrayObjectClass(string $className): bool;

    public function allowsRecursiveArrayIteratorForeachByRef(ObjectEntry $object): bool;

    public function recursiveArrayIteratorForeachCurrentByRef(ObjectEntry $object): Variable;
}
