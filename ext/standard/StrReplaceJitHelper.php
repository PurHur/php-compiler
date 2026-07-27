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
 * - Never search a reassigned suffix — NestedJIT sticky-reads the matched byte
 *   on the next `$hay[$i]` ("hell0 w0000"). Walk the original `$subject` only.
 * - Do not tally matches with int `$count++` / `++self::$lastCount` / marker-string
 *   appends in the match arm — NestedJIT thin AOT segfaults or `free(): invalid
 *   pointer`. {@see takeLastCount()} stays 0 after NestedJIT replaceArgv; callers
 *   that need `$count` under Zend use {@see VmString::strReplace()}.
 * - Avoid `\strlen`/`\strpos`/`\substr` here — those NestedJIT paths also miscompile
 *   under thin AOT for this helper (wrong output / abort).
 */
final class StrReplaceJitHelper
{
    private static int $lastCount = 0;

    public static function replaceArgv(string $search, string $replace, string $subject): string
    {
        self::$lastCount = 0;
        if ('' === $search) {
            return $subject;
        }
        $searchLen = self::byteLen($search);
        $subjectLen = self::byteLen($subject);
        $out = '';
        $i = 0;
        while ($i < $subjectLen) {
            $matched = true;
            $j = 0;
            $hi = $i;
            while ($j < $searchLen) {
                if ($hi >= $subjectLen || $subject[$hi] !== $search[$j]) {
                    $matched = false;
                    break;
                }
                ++$j;
                ++$hi;
            }
            if ($matched) {
                $out = self::concat($out, $replace);
                $k = 0;
                while ($k < $searchLen) {
                    ++$i;
                    ++$k;
                }
            } else {
                $out = self::concat($out, $subject[$i]);
                ++$i;
            }
        }

        return $out;
    }

    public static function ireplaceArgv(string $search, string $replace, string $subject): string
    {
        // Keep SSOT for case-insensitive via VmString (ASCII fold).
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
