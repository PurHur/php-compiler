<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_scrub() NestedJIT runtime (#34338 leftover of #6050).
 *
 * Leaf UTF-8 / ASCII / 8BIT scrub with '?' substitution (Zend default
 * mb_substitute_character). Encoding is always a compile-time-validated
 * literal from {@see JitMbScrub} — no ValueError here (NestedJIT throw
 * paths pollute the user module).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_scrub)
 */
final class MbScrubJitHelper
{
    public static function scrubArgv(string $value, string $encoding): string
    {
        $canonical = self::canonical($encoding);
        if ('8BIT' === $canonical) {
            return $value;
        }
        if ('ASCII' === $canonical) {
            return self::scrubAscii($value);
        }

        return self::scrubUtf8($value);
    }

    private static function canonical(string $encoding): string
    {
        $upper = strtoupper($encoding);
        if ('ASCII' === $upper || 'US-ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return 'UTF-8';
    }

    private static function scrubAscii(string $value): string
    {
        // Char compare — NestedJIT of ord()+int const misfires on high bytes (#34338).
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $value[$i];
            if ($ch <= "\x7F") {
                $out .= $ch;
            } else {
                $out .= '?';
            }
        }

        return $out;
    }

    private static function scrubUtf8(string $value): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                $out .= '?';
                ++$i;
                continue;
            }
            $out .= \substr($value, $i, $need + 1);
            $i += $need + 1;
        }

        return $out;
    }

    /**
     * @param-out int $need
     */
    private static function utf8SequenceValidAt(string $value, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($value[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($value[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }
}
