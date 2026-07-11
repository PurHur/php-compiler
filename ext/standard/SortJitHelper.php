<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * sort()/rsort() for compiled JIT/AOT modules (#12769, php-in-PHP).
 *
 * SSOT shared with {@see sort_} / {@see rsort_} VM execute()
 * php-src: ext/standard/array.c — php_array_sort
 */
final class SortJitHelper
{
    public static function sortPacked(HashTable $ht): void
    {
        VmArray::sortPackedInPlace($ht, StdlibConstants::SORT_REGULAR);
    }

    public static function sortPackedLocale(HashTable $ht): void
    {
        VmArray::sortPackedInPlace($ht, StdlibConstants::SORT_LOCALE_STRING);
    }

    public static function sortPackedNatural(HashTable $ht): void
    {
        VmArray::sortPackedInPlace($ht, StdlibConstants::SORT_NATURAL);
    }

    public static function sortPackedNaturalCase(HashTable $ht): void
    {
        VmArray::sortPackedInPlace($ht, StdlibConstants::SORT_NATURAL | StdlibConstants::SORT_FLAG_CASE);
    }

    public static function sortPackedReverse(HashTable $ht): void
    {
        VmArray::sortPackedReverseInPlace($ht, StdlibConstants::SORT_REGULAR);
    }
}
