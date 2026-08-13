<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Host/unit Oliver peel for similar_text (#9731, #26897).
 *
 * Thin AOT NestedJIT of this helper segfaults (#30810) — JIT/AOT uses
 * {@see JitSimilarTextKernel} instead (peer NaturalCompare #30088). Kept for
 * PHPUnit algorithm checks against {@see VmString}.
 *
 * php-src: ext/standard/string.c — php_similar_text, PHP_FUNCTION(similar_text)
 */
final class SimilarTextJitHelper
{
    public static function compute(string $string1, string $string2): int
    {
        $len1 = \strlen($string1);
        $len2 = \strlen($string2);
        if (0 === $len1 && 0 === $len2) {
            return 0;
        }

        return self::similarChar($string1, 0, $len1, $string2, 0, $len2);
    }

    private static function similarStr(
        string $txt1,
        int $off1,
        int $len1,
        string $txt2,
        int $off2,
        int $len2,
        int &$pos1,
        int &$pos2,
        int &$max,
        int &$count
    ): void {
        $max = 0;
        $count = 0;
        $pos1 = 0;
        $pos2 = 0;
        for ($p = 0; $p < $len1; ++$p) {
            for ($q = 0; $q < $len2; ++$q) {
                $l = 0;
                while (
                    $p + $l < $len1
                    && $q + $l < $len2
                    && $txt1[$off1 + $p + $l] === $txt2[$off2 + $q + $l]
                ) {
                    ++$l;
                }
                if ($l > $max) {
                    $max = $l;
                    ++$count;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }
    }

    private static function similarChar(
        string $txt1,
        int $off1,
        int $len1,
        string $txt2,
        int $off2,
        int $len2
    ): int {
        $pos1 = 0;
        $pos2 = 0;
        $max = 0;
        $count = 0;
        self::similarStr($txt1, $off1, $len1, $txt2, $off2, $len2, $pos1, $pos2, $max, $count);
        $sum = $max;
        if ($sum > 0) {
            if ($pos1 > 0 && $pos2 > 0 && $count > 1) {
                $sum += self::similarChar($txt1, $off1, $pos1, $txt2, $off2, $pos2);
            }
            $end1 = $pos1 + $max;
            $end2 = $pos2 + $max;
            if ($end1 < $len1 && $end2 < $len2) {
                $sum += self::similarChar(
                    $txt1,
                    $off1 + $end1,
                    $len1 - $end1,
                    $txt2,
                    $off2 + $end2,
                    $len2 - $end2
                );
            }
        }

        return $sum;
    }
}
