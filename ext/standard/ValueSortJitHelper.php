<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * asort()/arsort() for compiled JIT/AOT modules (#12771, php-in-PHP).
 *
 * Host/VM SSOT + unit tests. Thin standalone AOT uses Type\HashTable LLVM
 * ({@see \PHPCompiler\JIT\Builtin\ValueSortRuntime}) — NestedJIT of this helper
 * aborts on HashTable method stubs (#27227 / peer #26975).
 *
 * SSOT shared with {@see asort_} / {@see arsort_} VM execute()
 * php-src: ext/standard/array.c — php_array_asort / php_array_arsort
 */
final class ValueSortJitHelper
{
    public static function asortByValue(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        self::applySortedCopy($ht, VmArray::asortCopy($ht, $flags));
    }

    public static function asortByValueLocale(HashTable $ht): void
    {
        self::asortByValue($ht, StdlibConstants::SORT_LOCALE_STRING);
    }

    public static function arsortByValue(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        self::applySortedCopy($ht, VmArray::arsortCopy($ht, $flags));
    }

    private static function applySortedCopy(HashTable $ht, HashTable $sorted): void
    {
        $pairs = [];
        foreach ($sorted->exportKeyValuePairs(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $ht->reorderKeyedPairs($pairs);
    }
}
