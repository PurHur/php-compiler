<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * usort()/uksort()/uasort() closure comparators for compiled JIT/AOT modules (#15518, php-in-PHP).
 *
 * Returns a new HashTable built with NestedJIT-safe append/add/addIndex — avoids
 * replacePackedValues/reorderKeyedPairs which lack NestedJIT lowering (#24142).
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391).
 *
 * SSOT shared with {@see usort_} / {@see uksort_} / {@see uasort_} VM execute() closure paths
 * php-src: ext/standard/array.c — php_array_usort / php_array_uksort / php_array_uasort
 */
final class UsortJitHelper
{
    public static function sortPackedWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        // Inline context resolve — NestedJIT mis-types `: Context` returns as int (#20816 / #24142).
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->duplicateFrom($value);
            $values[] = $copy;
        }
        VmClosureCall::sortVariableValues($ctx, $values, VmClosureCall::resolve($closure));

        return self::packedFromValues($values);
    }

    public static function sortKeysWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $pairs = self::collectKeyedPairs($ht);
        VmClosureCall::sortKeyedPairsByKey($ctx, $pairs, VmClosureCall::resolve($closure));

        return self::fromKeyedPairs($pairs);
    }

    public static function sortValuesWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $pairs = self::collectKeyedPairs($ht);
        VmClosureCall::sortKeyedPairsByValue($ctx, $pairs, VmClosureCall::resolve($closure));

        return self::fromKeyedPairs($pairs);
    }

    /**
     * @param list<Variable> $values
     */
    private static function packedFromValues(array $values): HashTable
    {
        $out = new HashTable();
        foreach ($values as $value) {
            $out->append($value);
        }

        return $out;
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
    private static function fromKeyedPairs(array $pairs): HashTable
    {
        $out = new HashTable();
        foreach ($pairs as [$key, $value]) {
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $value);
            } else {
                $out->add($key->toString(), $value);
            }
        }

        return $out;
    }
}
