<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * usort()/uksort() closure comparators for compiled JIT/AOT modules (#15518, php-in-PHP).
 *
 * SSOT shared with {@see usort_} / {@see uksort_} VM execute() closure paths
 * php-src: ext/standard/array.c — php_array_usort / php_array_uksort
 */
final class UsortJitHelper
{
    public static function sortPackedWithClosure(HashTable $ht, Variable $closure): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'UsortJitHelper::sortPackedWithClosure() requires an active VM context in this compiler build'
            );
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->duplicateFrom($value);
            $values[] = $copy;
        }
        VmClosureCall::sortVariableValues($ctx, $values, VmClosureCall::resolve($closure));
        self::writePackedValues($ht, $values);
    }

    public static function sortKeysWithClosure(HashTable $ht, Variable $closure): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'UsortJitHelper::sortKeysWithClosure() requires an active VM context in this compiler build'
            );
        }
        $pairs = self::collectKeyedPairs($ht);
        VmClosureCall::sortKeyedPairsByKey($ctx, $pairs, VmClosureCall::resolve($closure));
        self::reorderFromPairs($ht, $pairs);
    }

    public static function sortValuesWithClosure(HashTable $ht, Variable $closure): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'UsortJitHelper::sortValuesWithClosure() requires an active VM context in this compiler build'
            );
        }
        $pairs = self::collectKeyedPairs($ht);
        VmClosureCall::sortKeyedPairsByValue($ctx, $pairs, VmClosureCall::resolve($closure));
        self::reorderFromPairs($ht, $pairs);
    }

    /**
     * @param list<Variable> $values
     */
    private static function writePackedValues(HashTable $ht, array $values): void
    {
        if (VmArray::isList($ht)) {
            $ht->replacePackedValues($values);

            return;
        }
        $ht->assignPackedList($values);
    }

    /**
     * @return list<array{0: Variable, 1: Variable}>
     */
    private static function collectKeyedPairs(HashTable $ht): array
    {
        $pairs = [];
        foreach ($ht->exportKeyValuePairs(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->duplicateFrom($key);
            $valCopy = new Variable();
            $valCopy->duplicateFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }

        return $pairs;
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private static function reorderFromPairs(HashTable $ht, array $pairs): void
    {
        $ht->reorderKeyedPairs($pairs);
    }
}
