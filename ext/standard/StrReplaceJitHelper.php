<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() for compiled JIT/AOT modules (#14779, php-in-PHP).
 *
 * SSOT: {@see VmString::strReplace()} / {@see VmString::strIreplace()}
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 *
 * NestedJIT user-script AOT (#23912 / peer #23871 / #27079 / #32621):
 * - Match via {@see findAt()} + {@see slice()} like {@see ExplodeJitHelper} / VmString::findSubstring
 *   — the inline subject walk sticky-reads `$subject[$hi]` when `$hi > $i` under NestedJIT.
 * - Inner `while` + `$str[$i]` miscompares under NestedJIT; {@see matchAt()} / {@see matchAtI()}
 *   recurse on the needle index instead (#36002 / differential c10_builtin).
 * - No int `$count++` in the match arm (NestedJIT segfault/abort).
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
        $out = '';
        $offset = 0;
        $len = self::byteLen($subject);
        while ($offset < $len) {
            $pos = self::findAt($subject, $search, $offset);
            if ($pos < 0) {
                $out = self::concat($out, self::slice($subject, $offset, $len - $offset));
                break;
            }
            $out = self::concat($out, self::slice($subject, $offset, $pos - $offset));
            $out = self::concat($out, $replace);
            $offset = $pos + $searchLen;
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
        $out = '';
        $offset = 0;
        $len = self::byteLen($subject);
        while ($offset < $len) {
            $pos = self::findAtI($subject, $search, $offset);
            if ($pos < 0) {
                $out = self::concat($out, self::slice($subject, $offset, $len - $offset));
                break;
            }
            $out = self::concat($out, self::slice($subject, $offset, $pos - $offset));
            $out = self::concat($out, $replace);
            $offset = $pos + $searchLen;
        }

        return $out;
    }

    /** NestedJIT-safe byte length (no \strlen). */
    private static function byteLen(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }

    /**
     * Find needle at/after offset; -1 if missing.
     * Walks with separate cursors — no `$s[$i+$j]` (#27079).
     */
    private static function findAt(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::byteLen($haystack);
        $needleLen = self::byteLen($needle);
        if ($needleLen < 1 || $offset > $hayLen) {
            return -1;
        }
        $i = $offset;
        while ($i < $hayLen) {
            if (self::matchAt($haystack, $needle, $i, $hayLen, $needleLen, 0)) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    /** Recurse on needle index — NestedJIT inner `while` + `$str[$i]` miscompares (c10_builtin / #36002). */
    private static function matchAt(
        string $haystack,
        string $needle,
        int $start,
        int $hayLen,
        int $needleLen,
        int $j
    ): bool {
        if ($j >= $needleLen) {
            return true;
        }
        $hi = $start + $j;
        if ($hi >= $hayLen) {
            return false;
        }
        if ($haystack[$hi] !== $needle[$j]) {
            return false;
        }

        return self::matchAt($haystack, $needle, $start, $hayLen, $needleLen, $j + 1);
    }

    private static function findAtI(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::byteLen($haystack);
        $needleLen = self::byteLen($needle);
        if ($needleLen < 1 || $offset > $hayLen) {
            return -1;
        }
        $i = $offset;
        while ($i < $hayLen) {
            if (self::matchAtI($haystack, $needle, $i, $hayLen, $needleLen, 0)) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    /** Case-insensitive peer of {@see matchAt} (NestedJIT inner-while string dim). */
    private static function matchAtI(
        string $haystack,
        string $needle,
        int $start,
        int $hayLen,
        int $needleLen,
        int $j
    ): bool {
        if ($j >= $needleLen) {
            return true;
        }
        $hi = $start + $j;
        if ($hi >= $hayLen) {
            return false;
        }
        if (!self::asciiFoldEq($haystack[$hi], $needle[$j])) {
            return false;
        }

        return self::matchAtI($haystack, $needle, $start, $hayLen, $needleLen, $j + 1);
    }

    /** Build a byte slice via concat (no \substr). */
    private static function slice(string $s, int $start, int $len): string
    {
        if ($len <= 0) {
            return '';
        }
        $strLen = self::byteLen($s);
        if ($start >= $strLen) {
            return '';
        }
        $out = '';
        $i = $start;
        $taken = 0;
        while ($taken < $len && isset($s[$i])) {
            $out = self::concat($out, $s[$i]);
            ++$i;
            ++$taken;
        }

        return $out;
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
