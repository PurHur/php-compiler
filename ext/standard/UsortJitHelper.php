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
 * Packed usort: NestedJIT `new HashTable()` segfaults under thin standalone AOT (#24156).
 * Writeback must call {@see HashTable::assignPackedList} on the receiver — NestedJIT of
 * {@see VmArray::writeReindexedValues} / {@see HashTable::replacePackedValues} leaves the
 * user array unsorted under thin AOT (#26954). Compare via {@see Variable::toInt()} on the
 * invoke result — {@see VmClosureCall::coerceUserSortCallbackResult} reads VM type tags that
 * NestedClosureInvoke value-boxes do not populate.
 *
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391).
 *
 * SSOT shared with {@see usort_} / {@see uksort_} / {@see uasort_} VM execute() closure paths
 * php-src: ext/standard/array.c — php_array_usort / php_array_uksort / php_array_uasort
 */
final class UsortJitHelper
{
    public static function sortPackedWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return $ht;
        }
        if (1 === $n) {
            // php-src still assigns new keys 0..n-1 for a single element (#25385).
            VmArray::reindexToListKeys($ht);

            return $ht;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $values = [];
        foreach ($ht->iterate() as $value) {
            $values[] = $value;
        }
        $n = \count($values);
        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = 0; $j < $n - $i - 1; ++$j) {
                $cmpVar = VmClosureInvoke::invokeVariable($closure, $values[$j], $values[$j + 1]);
                if ($cmpVar->toInt() > 0) {
                    $tmp = $values[$j];
                    $values[$j] = $values[$j + 1];
                    $values[$j + 1] = $tmp;
                }
            }
        }
        // Direct NestedJIT mutator — do not route through VmArray::writeReindexedValues (#26954).
        $ht->assignPackedList($values);

        return $ht;
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
        VmClosureCall::sortKeyedPairsByKeyViaTarget($pairs, $closure);

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
        VmClosureCall::sortKeyedPairsByValueViaTarget($pairs, $closure);

        return self::fromKeyedPairs($pairs);
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
