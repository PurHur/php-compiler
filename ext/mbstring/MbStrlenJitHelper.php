<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_strlen() NestedJIT runtime (#34625 leftover of #4405).
 *
 * NestedJIT must not call {@see VmMbstring::strlen} / {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}
 * — those silent-0 under thin AOT NestedJIT (peer {@see MbSearchJitHelper}). Counting uses strlen/ord
 * only; encoding canon matches {@see MbConvertEncodingJitHelper} (no CharsetEngine).
 *
 * Algorithm matches {@see \PHPCompiler\ext\standard\VmString::utf8CharLength} / `__compiler_utf8_strlen`.
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strlen)
 */
final class MbStrlenJitHelper
{
    public static function strlenArgv(string $string, string $encoding): int
    {
        $canon = self::canon($encoding);
        if ('UTF-8' === $canon) {
            return self::utf8CharLength($string);
        }
        if ('ASCII' === $canon || '8BIT' === $canon || 'ISO-8859-1' === $canon) {
            return self::byteLength($string);
        }
        // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
        throw new \ValueError(
            'mb_strlen(): Argument #2 ($encoding) must be a valid encoding, "'.$encoding.'" given'
        );
    }

    private static function canon(string $encoding): string
    {
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return 'UTF-8';
        }
        if (
            'ISO-8859-1' === $encoding || 'iso-8859-1' === $encoding
            || 'LATIN1' === $encoding || 'latin1' === $encoding
            || 'LATIN-1' === $encoding || 'latin-1' === $encoding
        ) {
            return 'ISO-8859-1';
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return 'ASCII';
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            return '8BIT';
        }

        return '';
    }

    /** NestedJIT-safe length: strlen silent-0 (#34264). */
    private static function byteLength(string $string): int
    {
        $n = 0;
        $i = 0;
        // Walk one byte at a time — NestedJIT strlen() can silent-0 (#34264 peer).
        while (isset($string[$i])) {
            ++$n;
            ++$i;
        }

        return $n;
    }

    /**
     * UTF-8 character count — illegal lead bytes count as one character
     * ({@see \PHPCompiler\ext\standard\VmString::utf8CharLength}).
     */
    private static function utf8CharLength(string $string): int
    {
        $byteLen = self::byteLength($string);
        $count = 0;
        $i = 0;
        while ($i < $byteLen) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $byteLen) {
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $byteLen) {
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $byteLen) {
                $i += 4;
            } else {
                $i += 1;
            }
            ++$count;
        }

        return $count;
    }
}
