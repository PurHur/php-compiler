<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * levenshtein() for compiled JIT/AOT modules (#14648, #26830, #30790, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmLevenshtein}, NestedJIT-bundled with this file.
 *
 * php-src: ext/standard/levenshtein.c — PHP_FUNCTION(levenshtein)
 */
final class LevenshteinJitHelper
{
    public static function computeArgv(
        string $string1,
        string $string2,
        int $insertionCost,
        int $replacementCost,
        int $deletionCost
    ): int {
        return VmLevenshtein::compute(
            $string1,
            $string2,
            $insertionCost,
            $replacementCost,
            $deletionCost
        );
    }
}
