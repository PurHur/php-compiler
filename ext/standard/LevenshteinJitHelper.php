<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * levenshtein() for compiled JIT/AOT modules (#14648, php-in-PHP).
 *
 * SSOT: {@see VmString::levenshtein()}
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
        return VmString::levenshtein(
            $string1,
            $string2,
            $insertionCost,
            $replacementCost,
            $deletionCost
        );
    }
}
