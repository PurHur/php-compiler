<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_check_encoding() NestedJIT runtime (#35211 leftover of #4571).
 *
 * NestedJIT must not call {@see VmMbstring::checkEncoding} / {@see CharsetEngine} —
 * those abort or silent-fail under thin AOT NestedJIT (peer {@see MbStrlenJitHelper}).
 * UTF-8 validity matches {@see MbDetectEncodingJitHelper::isValidUtf8}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_check_encoding)
 */
final class MbCheckEncodingJitHelper
{
    /**
     * Int-returning check — NestedJIT bool helpers are unreliable; peer strlenArgv (#34625).
     *
     * @return int 1 if valid in encoding, 0 otherwise
     */
    public static function checkArgv(string $value, string $encoding): int
    {
        $canon = self::canon($encoding);
        if ('' === $canon) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                'mb_check_encoding(): Argument #2 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }
        // ASCII / 8BIT: VmMbstring::isValidInEncoding always true (not Zend byte-check).
        if ('ASCII' === $canon || '8BIT' === $canon) {
            return 1;
        }

        return self::isValidUtf8($value) ? 1 : 0;
    }

    private static function canon(string $encoding): string
    {
        // Hand-rolled (no strtoupper) — NestedJIT of strtoupper+throw misfires module verify.
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return 'UTF-8';
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

    /** NestedJIT-safe UTF-8 — peer {@see MbDetectEncodingJitHelper::isValidUtf8}. */
    private static function isValidUtf8(string $string): bool
    {
        $len = self::byteLen($string);
        $i = 0;
        while ($i < $len) {
            $need = 0;
            if (!self::utf8SequenceValidAt($string, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /**
     * @param-out int $need
     */
    private static function utf8SequenceValidAt(string $string, int $len, int $i, ?int &$need = null): bool
    {
        $ch = $string[$i];
        if ($ch <= "\x7F") {
            $need = 0;

            return true;
        }
        $byte = \ord($ch);
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
        $j = 1;
        while ($j <= $need) {
            $nextCh = $string[$i + $j];
            if ($nextCh < "\x80" || $nextCh > "\xBF") {
                return false;
            }
            $next = \ord($nextCh);
            $cp = ($cp << 6) | ($next & 0x3F);
            ++$j;
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }

    /** NestedJIT-safe length: strlen silent-0 (#34264). */
    private static function byteLen(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }
}
