<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_ord() for compiled JIT/AOT modules (#34243 leftover of #33547 / #30759).
 *
 * Returns {@see StringStrpos::NOT_FOUND} (-1) on invalid first character so callers can box int|false.
 *
 * NestedJIT must not call {@see VmMbstring::ord} / {@see \PHPCompiler\ext\standard\VmString::isValidUtf8}
 * — those silent-return / misbehave under thin AOT NestedJIT. Decode is inlined with strlen/ord/substr
 * and range compares (peer {@see MbSearchJitHelper}).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::ord()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_ord)
 *
 * Zend looks at the first character only (trailing invalid bytes after a valid lead still yield the
 * codepoint); match that here rather than whole-string isValidUtf8.
 */
final class MbChrOrdJitHelper
{
    /**
     * mb_ord() — first character codepoint, or NOT_FOUND when the lead sequence is invalid.
     */
    public static function ordArgv(string $string, string $encoding): int
    {
        if ('' === $string) {
            throw new \ValueError('mb_ord(): Argument #1 ($string) must not be empty');
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return \ord(\substr($string, 0, 1));
        }

        return self::utf8FirstCodepoint($string);
    }

    /**
     * Decode the first UTF-8 character. Invalid / overlong / truncated / surrogate → NOT_FOUND.
     */
    private static function utf8FirstCodepoint(string $string): int
    {
        $byteLen = \strlen($string);
        $b0 = \ord(\substr($string, 0, 1));
        if ($b0 < 128) {
            return $b0;
        }
        // 2-byte: 0xC2..0xDF (reject overlong 0xC0/0xC1)
        if ($b0 >= 194 && $b0 <= 223) {
            if ($byteLen < 2) {
                return StringStrpos::NOT_FOUND;
            }
            $b1 = \ord(\substr($string, 1, 1));
            if ($b1 < 128 || $b1 > 191) {
                return StringStrpos::NOT_FOUND;
            }

            return (($b0 - 192) * 64) + ($b1 - 128);
        }
        // 3-byte: 0xE0..0xEF with overlong / surrogate checks
        if ($b0 >= 224 && $b0 <= 239) {
            if ($byteLen < 3) {
                return StringStrpos::NOT_FOUND;
            }
            $b1 = \ord(\substr($string, 1, 1));
            $b2 = \ord(\substr($string, 2, 1));
            if ($b1 < 128 || $b1 > 191 || $b2 < 128 || $b2 > 191) {
                return StringStrpos::NOT_FOUND;
            }
            // E0: b1 must be A0..BF (no overlong); ED: b1 must be 80..9F (no surrogates)
            if (224 === $b0 && $b1 < 160) {
                return StringStrpos::NOT_FOUND;
            }
            if (237 === $b0 && $b1 > 159) {
                return StringStrpos::NOT_FOUND;
            }
            $cp = (($b0 - 224) * 4096) + (($b1 - 128) * 64) + ($b2 - 128);
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                return StringStrpos::NOT_FOUND;
            }

            return $cp;
        }
        // 4-byte: 0xF0..0xF4
        if ($b0 >= 240 && $b0 <= 244) {
            if ($byteLen < 4) {
                return StringStrpos::NOT_FOUND;
            }
            $b1 = \ord(\substr($string, 1, 1));
            $b2 = \ord(\substr($string, 2, 1));
            $b3 = \ord(\substr($string, 3, 1));
            if ($b1 < 128 || $b1 > 191 || $b2 < 128 || $b2 > 191 || $b3 < 128 || $b3 > 191) {
                return StringStrpos::NOT_FOUND;
            }
            // F0: b1 must be 90..BF; F4: b1 must be 80..8F
            if (240 === $b0 && $b1 < 144) {
                return StringStrpos::NOT_FOUND;
            }
            if (244 === $b0 && $b1 > 143) {
                return StringStrpos::NOT_FOUND;
            }
            $cp = (($b0 - 240) * 262144) + (($b1 - 128) * 4096) + (($b2 - 128) * 64) + ($b3 - 128);
            if ($cp < 0 || $cp >= 0x110000) {
                return StringStrpos::NOT_FOUND;
            }

            return $cp;
        }

        return StringStrpos::NOT_FOUND;
    }
}
