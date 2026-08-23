<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_chr() / mb_ord() for compiled JIT/AOT modules (#34243 / #34250 leftover of #33547 / #33536).
 *
 * {@see ordArgv} returns {@see StringStrpos::NOT_FOUND} (-1) on invalid first character.
 * {@see chrArgv} returns string|false (nullish false → NestedJIT null).
 *
 * NestedJIT must not call {@see VmMbstring::ord} / {@see VmMbstring::chr} /
 * {@see \PHPCompiler\ext\standard\VmString::isValidUtf8} — those silent-return / misbehave under
 * thin AOT NestedJIT. Encode/decode is inlined with ord/substr/range compares (peer {@see MbSearchJitHelper}).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::chr()} / {@see VmMbstring::ord()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_chr), PHP_FUNCTION(mb_ord)
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

    /**
     * mb_chr() — codepoint to character, or false when invalid (#34250 leftover of #33536).
     *
     * @return string|false
     */
    public static function chrArgv(int $codepoint, string $encoding)
    {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($codepoint < 0 || $codepoint > 255) {
                return false;
            }

            return \chr($codepoint);
        }
        if (!self::isValidUnicodeCodepoint($codepoint)) {
            return false;
        }

        return self::encodeUtf8Codepoint($codepoint);
    }

    private static function isValidUnicodeCodepoint(int $cp): bool
    {
        if ($cp < 0 || $cp >= 0x110000) {
            return false;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }

        return true;
    }

    private static function encodeUtf8Codepoint(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }
}
