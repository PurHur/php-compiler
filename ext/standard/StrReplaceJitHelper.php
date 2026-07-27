<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() for compiled JIT/AOT modules (#14779, php-in-PHP).
 *
 * SSOT: {@see VmString::strReplace()} / {@see VmString::strIreplace()}
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 */
final class StrReplaceJitHelper
{
    private static int $lastCount = 0;

    public static function replaceArgv(string $search, string $replace, string $subject): string
    {
        // NestedJIT user-script AOT: avoid VmString by-ref $count + .= rebuild (#23912).
        // Build via concat of slices with index++ only (peer SprintfJitHelper / #23871).
        if ('' === $search) {
            self::$lastCount = 0;

            return $subject;
        }
        $searchLen = self::byteLen($search);
        $subjectLen = self::byteLen($subject);
        $count = 0;
        $out = '';
        $offset = 0;
        $remaining = $subjectLen;
        while ($remaining > 0) {
            $pos = self::findAt($subject, $subjectLen, $search, $searchLen, $offset);
            if ($pos < 0) {
                $out = self::concat($out, self::slice($subject, $offset, $remaining));
                break;
            }
            $prefixLen = 0;
            $t = $offset;
            while ($t < $pos) {
                ++$prefixLen;
                ++$t;
            }
            $out = self::concat($out, self::slice($subject, $offset, $prefixLen));
            $out = self::concat($out, $replace);
            $consumed = $prefixLen;
            $u = 0;
            while ($u < $searchLen) {
                ++$consumed;
                ++$u;
            }
            $offset = $pos;
            $v = 0;
            while ($v < $searchLen) {
                ++$offset;
                ++$v;
            }
            $w = 0;
            while ($w < $consumed) {
                --$remaining;
                ++$w;
            }
            ++$count;
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
            // NestedJIT: prefer isset+$n++ over strlen() (#23871).
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
            if (!isset($s[$idx])) {
                break;
            }
            $out = self::concat($out, $s[$idx]);
            ++$idx;
            --$left;
        }

        return $out;
    }

    /**
     * NestedJIT-safe find — never index with `$s[$i + $j]` (#23871 / #23912).
     */
    private static function findAt(string $hay, int $hayLen, string $needle, int $needleLen, int $offset): int
    {
        if ($needleLen <= 0) {
            return -1;
        }
        $remain = $hayLen;
        $t = 0;
        while ($t < $offset) {
            --$remain;
            ++$t;
        }
        if ($remain < $needleLen) {
            return -1;
        }
        $i = $offset;
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
