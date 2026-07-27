<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() for compiled JIT/AOT modules (#14779, php-in-PHP).
 *
 * SSOT: {@see VmString::strReplace()} / {@see VmString::strIreplace()}
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 *
 * NestedJIT user-script AOT (#23912 / peer #23871):
 * - Never index with `$s[$i+$j]`; walk with `++` only.
 * - After a match, search a fresh suffix from index 0 (advancing cursor on the same
 *   string value can sticky-read the matched byte → "hell0 w0000").
 * - Measure subject length once; shrink `$remLen` by arithmetic — do not re-`byteLen`
 *   the suffix (`isset` past the real end can sticky-read `'o'` → "hell0 w0rld0").
 */
final class StrReplaceJitHelper
{
    private static int $lastCount = 0;

    public static function replaceArgv(string $search, string $replace, string $subject): string
    {
        if ('' === $search) {
            self::$lastCount = 0;

            return $subject;
        }
        $searchLen = self::byteLen($search);
        $remLen = self::byteLen($subject);
        $count = 0;
        $out = '';
        $remaining = $subject;
        while ($remLen >= $searchLen) {
            $pos = self::findBounded($remaining, $remLen, $search, $searchLen);
            if ($pos < 0) {
                $out = self::concat($out, self::slice($remaining, 0, $remLen));
                self::$lastCount = $count;

                return $out;
            }
            $out = self::concat($out, self::slice($remaining, 0, $pos));
            $out = self::concat($out, $replace);
            $skip = $pos;
            $u = 0;
            while ($u < $searchLen) {
                ++$skip;
                ++$u;
            }
            $tailLen = 0;
            $t = $skip;
            while ($t < $remLen) {
                ++$tailLen;
                ++$t;
            }
            $remaining = self::slice($remaining, $skip, $tailLen);
            $remLen = $tailLen;
            ++$count;
        }
        if ($remLen > 0) {
            $out = self::concat($out, self::slice($remaining, 0, $remLen));
        }
        self::$lastCount = $count;

        return $out;
    }

    public static function ireplaceArgv(string $search, string $replace, string $subject): string
    {
        // Keep SSOT for case-insensitive via VmString (ASCII fold); count still NestedJIT-safe.
        $count = 0;
        $result = VmString::strIreplace($search, $replace, $subject, $count);
        self::$lastCount = $count;

        return $result;
    }

    private static function byteLen(string $s): int
    {
        $n = 0;
        while (true) {
            if (!isset($s[$n])) {
                return $n;
            }
            ++$n;
        }
    }

    private static function slice(string $s, int $start, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $out = '';
        $idx = $start;
        $left = $len;
        while ($left > 0) {
            $out = self::concat($out, $s[$idx]);
            ++$idx;
            --$left;
        }

        return $out;
    }

    /**
     * Find needle in the first `$hayLen` bytes — bound by counter, not isset (#23912).
     */
    private static function findBounded(string $hay, int $hayLen, string $needle, int $needleLen): int
    {
        if ($needleLen <= 0 || $hayLen < $needleLen) {
            return -1;
        }
        $i = 0;
        $remain = $hayLen;
        while ($remain >= $needleLen) {
            $j = 0;
            $hi = $i;
            $matched = true;
            while ($j < $needleLen) {
                if ($hay[$hi] !== $needle[$j]) {
                    $matched = false;
                    break;
                }
                ++$j;
                ++$hi;
            }
            if ($matched) {
                return $i;
            }
            ++$i;
            --$remain;
        }

        return -1;
    }

    /** NestedJIT-safe concat — avoid `$a .= $b` alone as a return path (#23871). */
    private static function concat(string $left, string $right): string
    {
        $out = '';
        $out .= $left;
        $out .= $right;

        return $out;
    }

    public static function takeLastCount(): int
    {
        return self::$lastCount;
    }
}
