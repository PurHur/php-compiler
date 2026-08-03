<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strnatcmp() / strnatcasecmp() for compiled JIT/AOT modules (#13535, php-in-PHP).
 *
 * Algorithm mirrors VmString::strnatcmp / VmString::strnatcasecmp but uses only
 * NestedJIT-safe string primitives (`strlen` / `ord`). Calling VmString from this helper
 * lowers to an external stub under thin AOT (writeNull → always 0) — #26975.
 *
 * SSOT (VM execute): VmString::strnatcmp / VmString::strnatcasecmp
 * php-src: ext/standard/string.c — PHP_FUNCTION(strnatcmp) / strnatcasecmp
 */
final class NaturalCompareJitHelper
{
    public static function strnatcmpArgv(string $a, string $b): int
    {
        return self::naturalCompare($a, $b, false);
    }

    public static function strnatcasecmpArgv(string $a, string $b): int
    {
        return self::naturalCompare($a, $b, true);
    }

    private static function naturalCompare(string $a, string $b, bool $caseInsensitive): int
    {
        $lenA = \strlen($a);
        $lenB = \strlen($b);
        $ia = 0;
        $ib = 0;
        while ($ia < $lenA && $ib < $lenB) {
            $ordA = \ord($a[$ia]);
            $ordB = \ord($b[$ib]);
            $digA = $ordA >= 48 && $ordA <= 57;
            $digB = $ordB >= 48 && $ordB <= 57;
            if ($digA && $digB) {
                while ($ia < $lenA && 48 === \ord($a[$ia])) {
                    ++$ia;
                }
                while ($ib < $lenB && 48 === \ord($b[$ib])) {
                    ++$ib;
                }
                $startA = $ia;
                $startB = $ib;
                while ($ia < $lenA) {
                    $o = \ord($a[$ia]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ia;
                }
                while ($ib < $lenB) {
                    $o = \ord($b[$ib]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ib;
                }
                $numLenA = $ia - $startA;
                $numLenB = $ib - $startB;
                if (0 === $numLenA && 0 === $numLenB) {
                    continue;
                }
                if ($numLenA !== $numLenB) {
                    return $numLenA <=> $numLenB;
                }
                for ($k = 0; $k < $numLenA; ++$k) {
                    $da = \ord($a[$startA + $k]);
                    $db = \ord($b[$startB + $k]);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }

                continue;
            }
            if ($caseInsensitive) {
                if ($ordA >= 65 && $ordA <= 90) {
                    $ordA += 32;
                }
                if ($ordB >= 65 && $ordB <= 90) {
                    $ordB += 32;
                }
            }
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
            ++$ia;
            ++$ib;
        }

        return ($lenA - $ia) <=> ($lenB - $ib);
    }
}
