<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_split() NestedJIT peel for thin AOT (#34391 leftover of #13367, #34400).
 *
 * Thin AOT cannot NestedJIT HashTable / preg_split arrays (peer {@see MbStrSplitJitHelper}).
 * Literal delimiter scan (any length); compile-time fold still uses {@see VmMbstring::split}.
 * Pieces from substr($string) — NestedJIT interned string returns are empty (#27181).
 *
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class MbSplitJitHelper
{
    public const JOIN_DELIM = "\x1E";

    /**
     * @return string|null null on failure; otherwise RS-joined parts
     */
    public static function splitJoinedArgv(string $pattern, string $string, int $limit): ?string
    {
        if ('' === $pattern) {
            return $string;
        }
        $plen = self::byteLength($pattern);
        if ($plen <= 0) {
            return $string;
        }
        if (0 === $limit || 1 === $limit) {
            return $string;
        }
        $maxSplits = 1048576;
        if ($limit > 1) {
            $maxSplits = 0;
            $k = 1;
            while ($k < $limit) {
                ++$maxSplits;
                ++$k;
            }
        }
        $len = self::byteLength($string);
        $joined = '';
        $first = 1;
        $start = 0;
        $i = 0;
        $splits = 0;
        $rs = "\x1E";
        $last = $len - $plen;
        while ($i <= $last) {
            $ok = 1;
            $k = 0;
            while ($k < $plen) {
                if ($string[$i + $k] !== $pattern[$k]) {
                    $ok = 0;
                    break;
                }
                $k = $k + 1;
            }
            if (1 === $ok) {
                if ($splits >= $maxSplits) {
                    break;
                }
                $partLen = $i - $start;
                $part = $partLen > 0 ? \substr($string, $start, $partLen) : \substr($string, 0, 0);
                if (1 === $first) {
                    $joined = $part;
                    $first = 0;
                } else {
                    $joined = $joined.$rs.$part;
                }
                ++$splits;
                $i = $i + $plen;
                $start = $i;
                continue;
            }
            ++$i;
        }
        $tailLen = $len - $start;
        $tail = $tailLen > 0 ? \substr($string, $start, $tailLen) : \substr($string, 0, 0);
        if (1 === $first) {
            return $tail;
        }

        return $joined.$rs.$tail;
    }

    private static function byteLength(string $string): int
    {
        $n = 0;
        while (isset($string[$n])) {
            ++$n;
            if ($n > 1048576) {
                break;
            }
        }

        return $n;
    }
}
