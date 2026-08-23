<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_ord() runtime for compiled JIT/AOT modules (#34243 leftover of #33547).
 *
 * NestedJIT must not call {@see VmMbstring::ord} / {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}
 * — those silent-return 0 under thin AOT NestedJIT. Decode uses strlen/ord/substr only; UTF-8 width
 * and codepoint math use range compares / subtraction (NestedJIT bitwise `&` hangs on lead bytes).
 *
 * Returns {@see StringStrpos::NOT_FOUND} (-1) for invalid UTF-8 / undecodable input so callers can
 * box int|false via {@see StringStrpos::boxFoundOffset}.
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::ord()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_ord)
 */
final class MbChrOrdJitHelper
{
    public static function ordArgv(string $string, string $encoding): int
    {
        if ('' === $string) {
            throw new \ValueError('mb_ord(): Argument #1 ($string) must not be empty');
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return \ord(\substr($string, 0, 1));
        }
        // UTF-8 (and default): first character only.
        if (!self::utf8FirstCharValid($string)) {
            return StringStrpos::NOT_FOUND;
        }

        return self::utf8FirstCodepoint($string);
    }

    private static function utf8FirstCharValid(string $string): bool
    {
        $byteLen = \strlen($string);
        if ($byteLen < 1) {
            return false;
        }
        $b0 = \ord(\substr($string, 0, 1));
        if ($b0 < 128) {
            return true;
        }
        // Lead must be C2–F4 (exclude overlong C0–C1 and F5+).
        if ($b0 < 194 || $b0 > 244) {
            return false;
        }
        $step = self::utf8Step($string, 0, $byteLen);
        if ($step < 2) {
            return false;
        }
        $i = 1;
        while ($i < $step) {
            $b = \ord(\substr($string, $i, 1));
            if ($b < 128 || $b > 191) {
                return false;
            }
            $i = $i + 1;
        }
        // Overlong / surrogate / out-of-range checks matching php-src utf8 validation loosely.
        $cp = self::utf8FirstCodepoint($string);
        if ($cp < 0 || $cp >= 0x110000) {
            return false;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }
        if (2 === $step && $cp < 0x80) {
            return false;
        }
        if (3 === $step && $cp < 0x800) {
            return false;
        }
        if (4 === $step && $cp < 0x10000) {
            return false;
        }

        return true;
    }

    /** UTF-8 first-character decode via subtraction (avoid NestedJIT-hanging bitwise masks). */
    private static function utf8FirstCodepoint(string $string): int
    {
        $byteLen = \strlen($string);
        $b0 = \ord(\substr($string, 0, 1));
        if ($b0 < 128) {
            return $b0;
        }
        $step = self::utf8Step($string, 0, $byteLen);
        if (2 === $step && $byteLen >= 2) {
            $b1 = \ord(\substr($string, 1, 1));

            return (($b0 - 192) * 64) + ($b1 - 128);
        }
        if (3 === $step && $byteLen >= 3) {
            $b1 = \ord(\substr($string, 1, 1));
            $b2 = \ord(\substr($string, 2, 1));

            return (($b0 - 224) * 4096) + (($b1 - 128) * 64) + ($b2 - 128);
        }
        if (4 === $step && $byteLen >= 4) {
            $b1 = \ord(\substr($string, 1, 1));
            $b2 = \ord(\substr($string, 2, 1));
            $b3 = \ord(\substr($string, 3, 1));

            return (($b0 - 240) * 262144) + (($b1 - 128) * 4096) + (($b2 - 128) * 64) + ($b3 - 128);
        }

        return StringStrpos::NOT_FOUND;
    }

    /** UTF-8 sequence width via range compares (avoid NestedJIT-hanging bitwise masks). */
    private static function utf8Step(string $string, int $bytePos, int $byteLen): int
    {
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord(\substr($string, $bytePos, 1));
        if ($byte < 128) {
            return 1;
        }
        if ($byte < 224) {
            return ($bytePos + 1 < $byteLen) ? 2 : 1;
        }
        if ($byte < 240) {
            return ($bytePos + 2 < $byteLen) ? 3 : 1;
        }
        if ($byte < 248) {
            return ($bytePos + 3 < $byteLen) ? 4 : 1;
        }

        return 1;
    }
}
