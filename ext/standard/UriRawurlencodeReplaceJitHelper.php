<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT-safe preg_replace_callback + rawurlencode($match[0]) for Nyholm Uri.php (#36382).
 *
 * Avoids NestedJIT of {@see PregAotFastPath} / thin preg bridges into the user AOT module when
 * the only callback is {@code rawurlencodeMatchZero}. php-src: ext/pcre/php_pcre.c
 * PHP_FUNCTION(preg_replace_callback) + Zend rawurlencode.
 *
 * Supported patterns (delimiter `/` or `#`):
 * - `/[CLASS]++/` / `/[CLASS]+/` / `/[CLASS]/` (positive class)
 * - `/(?:[^CLASS]++|%(?![A-Fa-f0-9]{2}))/` (Nyholm filterPath / filterQuery)
 *
 * @return string replaced subject, or unchanged subject when the pattern is unsupported
 *         (caller must not use this helper for unsupported shapes)
 */
final class UriRawurlencodeReplaceJitHelper
{
    private static string $classChars = '';

    private static int $negated = 0;

    private static int $quant = 1;

    private static int $nyholmEncode = 0;

    /** 1 = pattern parsed, 0 = unsupported */
    public static function patternSupported(string $pattern): int
    {
        return self::tryParse($pattern);
    }

    public static function replaceArgv(string $pattern, string $subject): string
    {
        if (1 !== self::tryParse($pattern)) {
            return $subject;
        }

        // Recursive concat — NestedJIT while+$out .= miscompiles to truncated tails (#36382).
        return self::replaceFrom($subject, 0);
    }

    private static function replaceFrom(string $subject, int $offset): string
    {
        $len = \strlen($subject);
        if ($offset >= $len) {
            return '';
        }
        if (1 !== self::findNext($subject, $offset)) {
            return \substr($subject, $offset);
        }
        $pos = self::$lastPos;
        $blen = self::$lastBodyLen;
        $prefix = $pos > $offset ? \substr($subject, $offset, $pos - $offset) : '';
        $enc = \rawurlencode(\substr($subject, $pos, $blen));

        return $prefix.$enc.self::replaceFrom($subject, $pos + $blen);
    }

    private static int $lastPos = -1;

    private static int $lastBodyLen = 0;

    private static function tryParse(string $pattern): int
    {
        self::$classChars = '';
        self::$negated = 0;
        self::$quant = 1;
        self::$nyholmEncode = 0;
        $plen = \strlen($pattern);
        if ($plen < 3) {
            return 0;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return 0;
        }
        $close = self::delimClose($pattern, $delim);
        if ($close < 2) {
            return 0;
        }
        $body = \substr($pattern, 1, $close - 1);
        $blen = \strlen($body);
        if ($blen > 28 && '(?:[^' === \substr($body, 0, 5)) {
            $classEnd = self::classClose($body, 5);
            if ($classEnd > 5) {
                $suffix = \substr($body, $classEnd);
                if (']++|%(?![A-Fa-f0-9]{2}))' === $suffix) {
                    $inner = \substr($body, 5, $classEnd - 5);
                    if (1 === self::expandClass($inner)) {
                        self::$negated = 1;
                        self::$quant = 2;
                        self::$nyholmEncode = 1;

                        return 1;
                    }
                }
            }
        }
        if ($blen < 2 || '[' !== \substr($body, 0, 1)) {
            return 0;
        }
        $pos = 1;
        if ('^' === \substr($body, 1, 1)) {
            self::$negated = 1;
            $pos = 2;
        }
        $classEnd = self::classClose($body, $pos);
        if ($classEnd < $pos) {
            return 0;
        }
        $inner = \substr($body, $pos, $classEnd - $pos);
        $after = \substr($body, $classEnd + 1);
        if ('' === $after) {
            self::$quant = 1;
        } elseif ('+' === $after || '++' === $after) {
            self::$quant = 2;
        } else {
            return 0;
        }
        if (1 !== self::expandClass($inner)) {
            return 0;
        }

        return 1;
    }

    private static function delimClose(string $pattern, string $delim): int
    {
        $len = \strlen($pattern);
        $i = 1;
        while ($i < $len) {
            $ch = \substr($pattern, $i, 1);
            if ('\\' === $ch) {
                $i += 2;
                continue;
            }
            if ($ch === $delim) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    private static function classClose(string $body, int $pos): int
    {
        $blen = \strlen($body);
        $i = $pos;
        if ($i < $blen && ']' === \substr($body, $i, 1)) {
            ++$i;
        }
        while ($i < $blen) {
            $ch = \substr($body, $i, 1);
            if ('\\' === $ch) {
                $i += 2;
                continue;
            }
            if (']' === $ch) {
                return $i;
            }
            ++$i;
        }

        return -1;
    }

    private static function expandClass(string $inner): int
    {
        self::$classChars = '';
        $len = \strlen($inner);
        $i = 0;
        while ($i < $len) {
            $ch = \substr($inner, $i, 1);
            if ('\\' === $ch) {
                if ($i + 1 >= $len) {
                    return 0;
                }
                $esc = \substr($inner, $i + 1, 1);
                if ('d' === $esc || 'D' === $esc || 's' === $esc || 'S' === $esc
                    || 'w' === $esc || 'W' === $esc) {
                    return 0;
                }
                self::$classChars .= $esc;
                $i += 2;
                continue;
            }
            if ($i + 2 < $len && '-' === \substr($inner, $i + 1, 1)) {
                $right = \substr($inner, $i + 2, 1);
                if ('\\' !== $right && ']' !== $right) {
                    $lo = \ord($ch);
                    $hi = \ord($right);
                    if ($lo <= $hi) {
                        $o = $lo;
                        while ($o <= $hi) {
                            self::$classChars .= \chr($o);
                            ++$o;
                        }
                        $i += 3;
                        continue;
                    }
                }
            }
            self::$classChars .= $ch;
            ++$i;
        }

        return '' === self::$classChars ? 0 : 1;
    }

    private static function inClass(string $ch): bool
    {
        if (1 !== \strlen($ch)) {
            return false;
        }
        $chars = self::$classChars;
        $n = \strlen($chars);
        $i = 0;
        while ($i < $n) {
            if ($ch === \substr($chars, $i, 1)) {
                return 0 === self::$negated;
            }
            ++$i;
        }

        return 1 === self::$negated;
    }

    private static function isHex(string $ch): bool
    {
        if (1 !== \strlen($ch)) {
            return false;
        }
        $o = \ord($ch);

        return ($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 70) || ($o >= 97 && $o <= 102);
    }

    /** @return int 1 matched (sets lastPos/lastBodyLen), 0 no match */
    private static function findNext(string $subject, int $offset): int
    {
        self::$lastPos = -1;
        self::$lastBodyLen = 0;
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            $ch = \substr($subject, $i, 1);
            if (1 === self::$nyholmEncode && '%' === $ch) {
                $h1 = $i + 1 < $subLen ? \substr($subject, $i + 1, 1) : '';
                $h2 = $i + 2 < $subLen ? \substr($subject, $i + 2, 1) : '';
                if (!self::isHex($h1) || !self::isHex($h2)) {
                    self::$lastPos = $i;
                    self::$lastBodyLen = 1;

                    return 1;
                }
            }
            if (self::inClass($ch)) {
                if (1 === self::$quant) {
                    self::$lastPos = $i;
                    self::$lastBodyLen = 1;

                    return 1;
                }
                $j = $i + 1;
                while ($j < $subLen && self::inClass(\substr($subject, $j, 1))) {
                    ++$j;
                }
                self::$lastPos = $i;
                self::$lastBodyLen = $j - $i;

                return 1;
            }
            ++$i;
        }

        return 0;
    }
}
