<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_split() NestedJIT peel for thin AOT (#34391 leftover of #13367).
 *
 * No HashTable (peer {@see MbStrSplitJitHelper} / #27660). No preg_* (no
 * `__compiler_preg_match` in NestedJIT). Literal delimiter walk; pieces from
 * substr of $string — NestedJIT constant string returns are empty (#27181).
 * Compare `$limit` directly (NestedJIT zeros copied int locals — peer MbTrim #34379).
 *
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class MbSplitJitHelper
{
    public const JOIN_DELIM = "\x1E";

    public static function splitArgv(string $pattern, string $string, int $limit): string
    {
        if ('' === $pattern) {
            return $string."\x1E";
        }
        $delim = "\x1E";
        $joined = '';
        $pos = 0;
        $n = 0;
        $first = 1;
        $len = self::byteLength($string);
        $plen = self::byteLength($pattern);
        $guard = $len + 2;
        while ($guard > 0) {
            $guard = $guard - 1;
            $atLimit = 0;
            if (0 === $limit && $n + 1 >= 1) {
                $atLimit = 1;
            }
            if ($limit > 0 && $n + 1 >= $limit) {
                $atLimit = 1;
            }
            if ($n + 1 >= 1048576) {
                $atLimit = 1;
            }
            if (1 === $atLimit) {
                $rest = $pos >= $len ? \substr($string, 0, 0) : \substr($string, $pos);
                if (1 === $first) {
                    $joined = $rest;
                } else {
                    $joined = $joined.$delim.$rest;
                }
                break;
            }
            $found = self::findFrom($string, $pattern, $pos, $len, $plen);
            if (-1 === $found) {
                $rest = $pos >= $len ? \substr($string, 0, 0) : \substr($string, $pos);
                if (1 === $first) {
                    $joined = $rest;
                } else {
                    $joined = $joined.$delim.$rest;
                }
                break;
            }
            $pieceLen = $found - $pos;
            $piece = $pieceLen > 0 ? \substr($string, $pos, $pieceLen) : \substr($string, 0, 0);
            if (1 === $first) {
                $joined = $piece;
                $first = 0;
            } else {
                $joined = $joined.$delim.$piece;
            }
            $n = $n + 1;
            $pos = $found + $plen;
            if ($pos > $len) {
                break;
            }
        }

        return $joined.$delim;
    }

    private static function findFrom(string $string, string $pattern, int $pos, int $len, int $plen): int
    {
        if ($plen <= 0 || $pos > $len) {
            return -1;
        }
        $i = $pos;
        $last = $len - $plen;
        while ($i <= $last) {
            $k = 0;
            $ok = 1;
            while ($k < $plen) {
                if ($string[$i + $k] !== $pattern[$k]) {
                    $ok = 0;
                    break;
                }
                $k = $k + 1;
            }
            if (1 === $ok) {
                return $i;
            }
            $i = $i + 1;
        }

        return -1;
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
