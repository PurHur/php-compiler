<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * levenshtein() for compiled JIT/AOT modules + NestedJIT-safe algorithm SSOT (#14648, #26830).
 *
 * Same-class only (peer RoundJitHelper / AbsJitHelper). Solo NestedJIT of this file must not
 * call the VmString static method — that unbound call lowers to writeNull → readLong → 0
 * under user-script AOT (fingerprint deps are not NestedJIT'd; see metaphone #26794).
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
        $len1 = self::byteLength($string1);
        $len2 = self::byteLength($string2);
        if (0 === $len1) {
            return $len2 * $insertionCost;
        }
        if (0 === $len2) {
            return $len1 * $deletionCost;
        }

        $prev = [];
        for ($j = 0; $j <= $len2; ++$j) {
            $prev[$j] = $j * $insertionCost;
        }
        for ($i = 1; $i <= $len1; ++$i) {
            $cur = [];
            $cur[0] = $i * $deletionCost;
            for ($j = 1; $j <= $len2; ++$j) {
                $subst = $string1[$i - 1] === $string2[$j - 1] ? 0 : $replacementCost;
                $cur[$j] = min(
                    $cur[$j - 1] + $insertionCost,
                    $prev[$j] + $deletionCost,
                    $prev[$j - 1] + $subst
                );
            }
            $prev = $cur;
        }

        return $prev[$len2];
    }

    /** Byte length without strlen()/VmString — NestedJIT-safe (peer RoundJitHelper). */
    private static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
    }
}
