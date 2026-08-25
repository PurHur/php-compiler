<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * sort()/rsort() for compiled JIT/AOT modules (#12769, #25385, #34702, php-in-PHP).
 *
 * SSOT shared with {@see sort_} / {@see rsort_} VM execute() — including
 * single-element non-list reindex via {@see VmArray::sortPackedInPlace()}.
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

    /** SORT_STRING|SORT_FLAG_CASE — php-src ARRAY_CMP_FUNC_STRING + case (#34702). */
    public static function sortPackedStringCase(HashTable $ht): void
    {
        VmArray::sortPackedInPlace($ht, StdlibConstants::SORT_STRING | StdlibConstants::SORT_FLAG_CASE);
    }

    public static function sortPackedReverse(HashTable $ht): void
    {
        VmArray::sortPackedReverseInPlace($ht, StdlibConstants::SORT_REGULAR);
    }

    /** rsort() SORT_STRING|SORT_FLAG_CASE (#34702). */
    public static function sortPackedReverseStringCase(HashTable $ht): void
    {
        VmArray::sortPackedReverseInPlace($ht, StdlibConstants::SORT_STRING | StdlibConstants::SORT_FLAG_CASE);
    }
}
