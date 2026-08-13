<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT-safe Levenshtein (php-src ext/standard/levenshtein.c semantics).
 *
 * DP row is a fixed-width digit string — PHP arrays abort under NestedJIT AOT (#30790).
 * Index updates use `$j = $j + 1` (not `++$j` / advanceIdx-with-plusplus) — NestedJIT AOT
 * has SIGSEGV'd on the plusplus form in this helper.
 *
 * Peer {@see VmMetaphone}: NestedJIT-bundle with {@see LevenshteinJitHelper}.
 *
 * php-src: ext/standard/levenshtein.c — PHP_FUNCTION(levenshtein)
 */
final class VmLevenshtein
{
    public static function compute(string $a, string $b, int $ins, int $rep, int $del): int
    {
        $len1 = \strlen($a);
        $len2 = \strlen($b);
        if (0 === $len1) {
            return $len2 * $ins;
        }
        if (0 === $len2) {
            return $len1 * $del;
        }

        $row = '';
        $j = 0;
        while ($j <= $len2) {
            $row = $row.'0000';
            $j = $j + 1;
        }
        $j = 0;
        while ($j <= $len2) {
            $row = self::set($row, $j, $j * $ins);
            $j = $j + 1;
        }

        $i = 1;
        while ($i <= $len1) {
            $ca = \substr($a, $i - 1, 1);
            $prev = self::get($row, 0);
            $row = self::set($row, 0, $i * $del);
            $j = 1;
            while ($j <= $len2) {
                $cb = \substr($b, $j - 1, 1);
                $cost = ($ca === $cb) ? 0 : $rep;
                $delCost = self::get($row, $j) + $del;
                $insCost = self::get($row, $j - 1) + $ins;
                $repCost = $prev + $cost;
                $min = $delCost;
                if ($insCost < $min) {
                    $min = $insCost;
                }
                if ($repCost < $min) {
                    $min = $repCost;
                }
                $prev = self::get($row, $j);
                $row = self::set($row, $j, $min);
                $j = $j + 1;
            }
            $i = $i + 1;
        }

        return self::get($row, $len2);
    }

    private static function get(string $row, int $j): int
    {
        return (int) \substr($row, $j * 4, 4);
    }

    private static function set(string $row, int $j, int $v): string
    {
        return \substr($row, 0, $j * 4).self::pad4($v).\substr($row, ($j + 1) * 4);
    }

    private static function pad4(int $v): string
    {
        if ($v < 10) {
            return '000'.(string) $v;
        }
        if ($v < 100) {
            return '00'.(string) $v;
        }
        if ($v < 1000) {
            return '0'.(string) $v;
        }

        return (string) $v;
    }
}
