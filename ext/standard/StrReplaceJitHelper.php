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
 *   ("hell0 w0000"). Walk the original `$subject` only.
 * - No int `$count++` / marker appends in the match arm (NestedJIT segfault/abort).
 * - No `\strlen`/`\strpos`/`\substr` / explode+implode lowering for this helper.
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
