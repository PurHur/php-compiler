<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\Web\Superglobals;

/**
 * usort()/uksort()/uasort() closure comparators (#15518 / #27217, php-in-PHP).
 *
 * Packed usort: NestedJIT into thin AOT via {@see \PHPCompiler\JIT\Builtin\UsortRuntime}
 * + {@see HashTable::assignPackedList} (#26954 / #24156).
 *
 * uksort/uasort Closures under thin AOT: pure LLVM {@see \PHPCompiler\JIT\UsortKeyedLlvm}
 * — NestedJIT of these methods aborts (#27217). Host/VM still uses these PHP bodies via
 * {@see uksort_} / {@see uasort_} execute() paths that call {@see VmClosureCall} instead.
 *
 * Compare via {@see Variable::toInt()} on NestedClosureInvoke results when NestedJIT'd.
 *
 * Thin standalone AOT: {@see VmActiveContextJitHelper::resolve()} → sg_vm_context (#17391).
 *
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

    /**
     * Host/unit SSOT for keyed sorts; thin AOT uses {@see \PHPCompiler\JIT\UsortKeyedLlvm}.
     */
    public static function sortKeysWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $pairs = [];
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            $pairs[] = $pair;
        }
        $n = \count($pairs);
        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = 0; $j < $n - $i - 1; ++$j) {
                $left = $pairs[$j];
                $right = $pairs[$j + 1];
                $cmpVar = VmClosureInvoke::invokeVariable($closure, $left[0], $right[0]);
                if ($cmpVar->toInt() > 0) {
                    $pairs[$j] = $right;
                    $pairs[$j + 1] = $left;
                }
            }
        }
        $ht->reorderKeyedPairs($pairs);

        return $ht;
    }

    /**
     * Host/unit SSOT for value-preserving sorts; thin AOT uses {@see \PHPCompiler\JIT\UsortKeyedLlvm}.
     */
    public static function sortValuesWithClosure(HashTable $ht, Variable $closure): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            $ctx = VmActiveContextJitHelper::resolve();
        }
        $pairs = [];
        foreach ($ht->exportKeyValuePairs(true) as $pair) {
            $pairs[] = $pair;
        }
        $n = \count($pairs);
        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = 0; $j < $n - $i - 1; ++$j) {
                $left = $pairs[$j];
                $right = $pairs[$j + 1];
                $cmpVar = VmClosureInvoke::invokeVariable($closure, $left[1], $right[1]);
                if ($cmpVar->toInt() > 0) {
                    $pairs[$j] = $right;
                    $pairs[$j + 1] = $left;
                }
            }
        }
        $ht->reorderKeyedPairs($pairs);

        return $ht;
    }
}
