<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * ksort()/krsort() for compiled JIT/AOT modules (#12770, php-in-PHP).
 *
 * Host/VM SSOT + unit tests. Thin standalone AOT uses Type\HashTable LLVM
 * ({@see \PHPCompiler\JIT\Builtin\KeySortRuntime}) — NestedJIT of this helper
 * aborts on HashTable method stubs (#27227 / peer #26975).
 *
 * SSOT shared with {@see ksort_} / {@see krsort_} VM execute()
 * php-src: ext/standard/array.c — php_array_ksort / php_array_krsort
 */
final class KeySortJitHelper
{
    public static function ksortByKey(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        if ($ht->getNumElements() < 2 || VmArray::isList($ht)) {
            return;
        }
        self::applySortedCopy($ht, VmArray::ksortCopy($ht, $flags));
    }

    public static function ksortByKeyLocale(HashTable $ht): void
    {
        self::ksortByKey($ht, StdlibConstants::SORT_LOCALE_STRING);
    }

    public static function krsortByKey(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        if ($ht->getNumElements() < 2) {
            return;
        }
        self::applySortedCopy($ht, VmArray::krsortCopy($ht, $flags));
    }

    private static function applySortedCopy(HashTable $ht, HashTable $sorted): void
    {
        $pairs = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        $ht->reorderKeyedPairs($pairs);
    }
}
