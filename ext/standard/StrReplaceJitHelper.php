<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() for compiled JIT/AOT modules (#14779, php-in-PHP).
 *
 * SSOT: {@see VmString::strReplace()} / {@see VmString::strIreplace()}
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 *
 * NestedJIT user-script AOT (#23912 / peer #23871 / #27079):
 * - Never index with `$s[$i+$j]`; walk with `++` only.
 * - Never search a reassigned suffix — NestedJIT sticky-reads the matched byte
 *   ("hell0 w0000"). Walk the original `$subject` only.
 * - No int `$count++` / marker appends in the match arm (NestedJIT segfault/abort).
 * - No `\strlen`/`\strpos`/`\substr` / explode+implode lowering for this helper.
 * - No `VmString::*` calls from NestedJIT path (#27079 — empty AOT for ireplace).
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
                if ($hi >= $subjectLen || !self::asciiFoldEq($subject[$hi], $search[$j])) {
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

    /** ASCII A–Z / a–z fold for NestedJIT (no VmString / ord / strlen). */
    private static function asciiFoldEq(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return self::asciiLowerChar($a) === self::asciiLowerChar($b);
    }

    private static function asciiLowerChar(string $c): string
    {
        return match ($c) {
            'A' => 'a',
            'B' => 'b',
            'C' => 'c',
            'D' => 'd',
            'E' => 'e',
            'F' => 'f',
            'G' => 'g',
            'H' => 'h',
            'I' => 'i',
            'J' => 'j',
            'K' => 'k',
            'L' => 'l',
            'M' => 'm',
            'N' => 'n',
            'O' => 'o',
            'P' => 'p',
            'Q' => 'q',
            'R' => 'r',
            'S' => 's',
            'T' => 't',
            'U' => 'u',
            'V' => 'v',
            'W' => 'w',
            'X' => 'x',
            'Y' => 'y',
            'Z' => 'z',
            default => $c,
        };
    }

    public static function takeLastCount(): int
    {
        return self::$lastCount;
    }
}
