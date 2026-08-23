<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_chr() / mb_ord() for compiled JIT/AOT modules (#34243 / #34250 leftovers of #30759).
 *
 * mb_ord: Returns {@see StringStrpos::NOT_FOUND} (-1) on invalid first character so callers can box int|false.
 * mb_chr: Returns string|false (false → NestedJIT nullish for {@see JitMbChrOrd} boxing).
 *
 * NestedJIT must not call {@see VmMbstring::ord}/{@see VmMbstring::chr} / {@see \PHPCompiler\ext\standard\VmString::isValidUtf8}
 * — those silent-return / misbehave under thin AOT NestedJIT. Encode/decode is inlined with strlen/ord/substr
 * and range compares (peer {@see MbSearchJitHelper}). Avoid PHP {@see chr()} (typed mixed → TypeError under NestedJIT).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::chr()} / {@see VmMbstring::ord()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_chr) / PHP_FUNCTION(mb_ord)
 *
 * Zend mb_ord looks at the first character only (trailing invalid bytes after a valid lead still yield the
 * codepoint); match that here rather than whole-string isValidUtf8.
 */
final class MbChrOrdJitHelper
{
    /**
     * All 256 bytes as a literal — NestedJIT-safe substitute for chr($b).
     */
    private static function allBytes(): string
    {
        return "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
            ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f"
            ."\x20\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2a\x2b\x2c\x2d\x2e\x2f"
            ."\x30\x31\x32\x33\x34\x35\x36\x37\x38\x39\x3a\x3b\x3c\x3d\x3e\x3f"
            ."\x40\x41\x42\x43\x44\x45\x46\x47\x48\x49\x4a\x4b\x4c\x4d\x4e\x4f"
            ."\x50\x51\x52\x53\x54\x55\x56\x57\x58\x59\x5a\x5b\x5c\x5d\x5e\x5f"
            ."\x60\x61\x62\x63\x64\x65\x66\x67\x68\x69\x6a\x6b\x6c\x6d\x6e\x6f"
            ."\x70\x71\x72\x73\x74\x75\x76\x77\x78\x79\x7a\x7b\x7c\x7d\x7e\x7f"
            ."\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f"
            ."\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f"
            ."\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf"
            ."\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf"
            ."\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf"
            ."\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf"
            ."\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef"
            ."\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";
    }

    /** One byte 0..255 without PHP chr() (NestedJIT TypeError). */
    private static function byte(int $b): string
    {
        return \substr(self::allBytes(), $b, 1);
    }

    /**
     * mb_chr() — encode codepoint, or false when out of range / surrogate.
     *
     * @return string|false
     */
    public static function chrArgv(int $codepoint, string $encoding)
    {
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($codepoint < 0 || $codepoint > 255) {
                return false;
            }

            return self::byte($codepoint);
        }

        // UTF-8 (and default)
        if ($codepoint < 0 || $codepoint >= 0x110000) {
            return false;
        }
        if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
            return false;
        }

        return self::encodeUtf8($codepoint);
    }

    /**
     * Encode a validated Unicode scalar as UTF-8. Uses arithmetic (no NestedJIT-hanging & loops).
     */
    private static function encodeUtf8(int $cp): string
    {
        if ($cp < 128) {
            return self::byte($cp);
        }
        if ($cp < 2048) {
            $b1 = $cp - (self::divFloor($cp, 64) * 64);
            $b0 = self::divFloor($cp, 64);

            return self::byte(192 + $b0).self::byte(128 + $b1);
        }
        if ($cp < 65536) {
            $b2 = $cp - (self::divFloor($cp, 64) * 64);
            $cp2 = self::divFloor($cp, 64);
            $b1 = $cp2 - (self::divFloor($cp2, 64) * 64);
            $b0 = self::divFloor($cp2, 64);

            return self::byte(224 + $b0).self::byte(128 + $b1).self::byte(128 + $b2);
        }
        $b3 = $cp - (self::divFloor($cp, 64) * 64);
        $cp2 = self::divFloor($cp, 64);
        $b2 = $cp2 - (self::divFloor($cp2, 64) * 64);
        $cp3 = self::divFloor($cp2, 64);
        $b1 = $cp3 - (self::divFloor($cp3, 64) * 64);
        $b0 = self::divFloor($cp3, 64);

        return self::byte(240 + $b0).self::byte(128 + $b1).self::byte(128 + $b2).self::byte(128 + $b3);
    }

    /** Non-negative integer division (NestedJIT-safe; cp is always ≥ 0 here). */
    private static function divFloor(int $n, int $d): int
    {
        return (int) ($n / $d);
    }

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
