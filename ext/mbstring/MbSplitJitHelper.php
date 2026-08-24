<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_split() NestedJIT peel for thin AOT (#34391 leftover of #13367).
 *
 * Thin AOT cannot NestedJIT HashTable / preg_split arrays / preg_replace
 * (peer {@see MbStrSplitJitHelper} / #26870). Single-byte delimiter scan matches
 * Onig/PCRE for literal one-byte patterns; longer patterns fall back to a
 * no-split copy of $string (compile-time fold still uses {@see VmMbstring::split}).
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
        if (1 !== $plen) {
            return $string;
        }
        $delim = $pattern[0];
        $maxSplits = 1048576;
        if (0 === $limit || 1 === $limit) {
            return $string;
        }
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
        while ($i < $len) {
            $ch = $string[$i];
            if ($ch === $delim) {
                if ($splits >= $maxSplits) {
                    break;
                }
                $part = \substr($string, $start, $i - $start);
                if (1 === $first) {
                    $joined = $part;
                    $first = 0;
                } else {
                    $joined = $joined.$rs.$part;
                }
                ++$splits;
                $start = $i + 1;
            }
            ++$i;
        }
        $tail = \substr($string, $start, $len - $start);
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
