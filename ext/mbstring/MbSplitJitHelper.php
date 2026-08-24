<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_split() NestedJIT peel for thin AOT (#34391 leftover of #13367, php-in-PHP).
 *
 * No arrays / HashTable / implode / preg_split under NestedJIT (peer {@see MbStrSplitJitHelper}).
 * Literal-delimiter peel via index walk + concat; regex-meta patterns return ''.
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::split}
 * php-src: ext/mbstring/php_mbregex.c — PHP_FUNCTION(mb_split)
 */
final class MbSplitJitHelper
{
    public const JOIN_DELIM = "\x1E";

    public static function splitArgv(string $pattern, string $string, string $limitEnc): string
    {
        if ('' === $pattern) {
            return $string;
        }
        if (self::patternHasRegexMeta($pattern)) {
            return '';
        }

        return self::literalSplitJoined($pattern, $string, self::parseLimitEnc($limitEnc));
    }

    private static function parseLimitEnc(string $enc): int
    {
        if ('' === $enc) {
            return -1;
        }
        $neg = 0;
        $i = 0;
        if ('-' === $enc[0]) {
            $neg = 1;
            $i = 1;
        }
        $n = 0;
        $any = 0;
        while (isset($enc[$i])) {
            $d = \ord($enc[$i]) - 48;
            if ($d < 0 || $d > 9) {
                break;
            }
            $n = $n * 10 + $d;
            $any = 1;
            ++$i;
            if ($i > 20) {
                break;
            }
        }
        if (0 === $any) {
            return -1;
        }
        if (1 === $neg) {
            return -1;
        }

        return $n;
    }

    private static function patternHasRegexMeta(string $pattern): bool
    {
        $i = 0;
        while (isset($pattern[$i])) {
            $c = $pattern[$i];
            if ('\\' === $c || '.' === $c || '*' === $c || '+' === $c || '?' === $c
                || '[' === $c || ']' === $c || '(' === $c || ')' === $c || '{' === $c
                || '}' === $c || '^' === $c || '$' === $c || '|' === $c) {
                return true;
            }
            ++$i;
            if ($i > 4096) {
                break;
            }
        }

        return false;
    }

    /**
     * Literal delimiter split joined with JOIN_DELIM (Onig mb_split ≈ explode).
     * php-src: limit 0 skips the split loop (same as 1); negative → unlimited.
     */
    private static function literalSplitJoined(string $pattern, string $string, int $limit): string
    {
        $max = $limit < 0 ? -1 : ($limit === 0 ? 1 : $limit);
        $slen = self::byteLength($string);
        $plen = self::byteLength($pattern);
        if (0 === $plen) {
            return $string;
        }

        $joined = '';
        $first = 1;
        $outDelim = "\x1E";
        $start = 0;
        $nParts = 0;
        $guard = $slen + 2;
        while ($guard > 0) {
            $guard = $guard - 1;
            if (-1 !== $max && $nParts + 1 >= $max) {
                $part = self::byteSubstr($string, $start, $slen - $start);
                if (1 === $first) {
                    $joined = $part;
                } else {
                    $joined = $joined.$outDelim.$part;
                }
                break;
            }
            $found = self::findLiteral($string, $pattern, $start, $slen, $plen);
            if ($found < 0) {
                $part = self::byteSubstr($string, $start, $slen - $start);
                if (1 === $first) {
                    $joined = $part;
                } else {
                    $joined = $joined.$outDelim.$part;
                }
                break;
            }
            $part = self::byteSubstr($string, $start, $found - $start);
            if (1 === $first) {
                $joined = $part;
                $first = 0;
            } else {
                $joined = $joined.$outDelim.$part;
            }
            ++$nParts;
            $start = $found + $plen;
            if ($start > $slen) {
                $joined = $joined.$outDelim;
                break;
            }
        }

        return $joined;
    }

    private static function findLiteral(
        string $haystack,
        string $needle,
        int $from,
        int $hayLen,
        int $needleLen
    ): int {
        if ($from > $hayLen - $needleLen) {
            return -1;
        }
        $i = $from;
        while ($i <= $hayLen - $needleLen) {
            $j = 0;
            while ($j < $needleLen && $haystack[$i + $j] === $needle[$j]) {
                ++$j;
            }
            if ($j === $needleLen) {
                return $i;
            }
            ++$i;
            if ($i > $hayLen) {
                break;
            }
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

    private static function byteSubstr(string $string, int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return \substr($string, $start, $length);
    }
}
