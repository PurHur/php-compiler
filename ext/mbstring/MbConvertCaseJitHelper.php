<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_case(TITLE|TITLE_SIMPLE) NestedJIT runtime (#34284 / #34290 leftover of #34280).
 *
 * Separate from {@see MbCaseJitHelper}: calling {@see VmMbstring} / {@see Utf8CaseMap} from a
 * titleArgv entry SIGSEGVs/aborts under thin AOT NestedJIT. This unit uses only strlen/ord/substr
 * + NestedJIT-safe upper/lower maps (Latin-1 + Cyrillic + Greek; peer MbChrOrdJitHelper — no
 * PHP chr()). Illegal UTF-8 bytes emit default `?` (#34344 leftover of #34340).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
 */
final class MbConvertCaseJitHelper
{
    public static function titleArgv(string $string, string $encoding): string
    {
        return self::titleCase($string);
    }

    public static function titleSimpleArgv(string $string, string $encoding): string
    {
        return self::titleCase($string);
    }

    private static function titleCase(string $string): string
    {
        $out = '';
        $upperNext = true;
        $len = \strlen($string);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($string, $i);
            if ($charLen <= 0) {
                break;
            }
            // Illegal lead/truncated → default '?' (mb_substitute_character); keep upperNext
            // so Zend `chr(0x80)."Ab"` → `?Ab` (#34344 leftover of #34340).
            if (self::isIllegalUtf8Byte($string, $i, $charLen)) {
                $out .= '?';
                $i += $charLen;
                continue;
            }
            $cp = self::utf8CpAt($string, $i, $charLen);
            if ($upperNext) {
                if (0xDF === $cp) {
                    $out .= 'Ss';
                } else {
                    $out .= self::encodeUtf8(self::toUpperCp($cp));
                }
                $upperNext = false;
            } else {
                $out .= self::encodeUtf8(self::toLowerCp($cp));
            }
            if (self::isTitleDelimiter($cp)) {
                $upperNext = true;
            }
            $i += $charLen;
        }

        return $out;
    }

    /**
     * Single-byte walk result with lead ≥ 0x80 ⇒ illegal (truncated / bad lead / bad cont).
     * Peer {@see MbCaseJitHelper} (#34343).
     */
    private static function isIllegalUtf8Byte(string $string, int $offset, int $charLen): bool
    {
        if (1 !== $charLen) {
            return false;
        }
        $b0 = \ord(\substr($string, $offset, 1));

        return $b0 >= 128;
    }

    private static function isTitleDelimiter(int $codepoint): bool
    {
        return 0x20 === $codepoint
            || 0x09 === $codepoint
            || 0x0A === $codepoint
            || 0x0B === $codepoint
            || 0x0C === $codepoint
            || 0x0D === $codepoint
            || 0x2D === $codepoint
            || 0x2010 === $codepoint
            || 0x2011 === $codepoint
            || 0x2012 === $codepoint
            || 0x2013 === $codepoint
            || 0x2014 === $codepoint
            || 0x2F === $codepoint
            || 0x5C === $codepoint;
    }

    private static function toUpperCp(int $cp): int
    {
        if ($cp >= 97 && $cp <= 122) {
            return $cp - 32;
        }
        if ($cp >= 0xE0 && $cp <= 0xFE && 0xF7 !== $cp) {
            return $cp - 0x20;
        }
        if (0xFF === $cp) {
            return 0x178;
        }
        // Greek small → capital (php_unicode / UnicodeData; final sigma → Sigma).
        if (0x3C2 === $cp || 0x3C3 === $cp) {
            return 0x3A3;
        }
        if ($cp >= 0x3B1 && $cp <= 0x3C9) {
            return $cp - 0x20;
        }
        // Cyrillic small → capital (а-я / ё).
        if (0x451 === $cp) {
            return 0x401;
        }
        if ($cp >= 0x430 && $cp <= 0x44F) {
            return $cp - 0x20;
        }

        return $cp;
    }

    private static function toLowerCp(int $cp): int
    {
        if ($cp >= 65 && $cp <= 90) {
            return $cp + 32;
        }
        if ($cp >= 0xC0 && $cp <= 0xDE && 0xD7 !== $cp) {
            return $cp + 0x20;
        }
        if (0x178 === $cp) {
            return 0xFF;
        }
        // Greek capital → small.
        if (0x3A3 === $cp) {
            return 0x3C3;
        }
        if ($cp >= 0x391 && $cp <= 0x3A9) {
            return $cp + 0x20;
        }
        // Cyrillic capital → small (А-Я / Ё).
        if (0x401 === $cp) {
            return 0x451;
        }
        if ($cp >= 0x410 && $cp <= 0x42F) {
            return $cp + 0x20;
        }

        return $cp;
    }

    private static function utf8ByteLenAt(string $string, int $offset): int
    {
        $byteLen = \strlen($string);
        if ($offset >= $byteLen) {
            return 0;
        }
        $b0 = \ord(\substr($string, $offset, 1));
        if ($b0 < 128) {
            return 1;
        }
        if ($b0 >= 194 && $b0 <= 223) {
            if ($offset + 1 >= $byteLen) {
                return 1;
            }
            $b1 = \ord(\substr($string, $offset + 1, 1));
            if ($b1 < 128 || $b1 > 191) {
                return 1;
            }

            return 2;
        }
        if ($b0 >= 224 && $b0 <= 239) {
            if ($offset + 2 >= $byteLen) {
                return 1;
            }
            $b1 = \ord(\substr($string, $offset + 1, 1));
            $b2 = \ord(\substr($string, $offset + 2, 1));
            if ($b1 < 128 || $b1 > 191 || $b2 < 128 || $b2 > 191) {
                return 1;
            }

            return 3;
        }
        if ($b0 >= 240 && $b0 <= 244) {
            if ($offset + 3 >= $byteLen) {
                return 1;
            }
            $b1 = \ord(\substr($string, $offset + 1, 1));
            $b2 = \ord(\substr($string, $offset + 2, 1));
            $b3 = \ord(\substr($string, $offset + 3, 1));
            if ($b1 < 128 || $b1 > 191 || $b2 < 128 || $b2 > 191 || $b3 < 128 || $b3 > 191) {
                return 1;
            }

            return 4;
        }

        return 1;
    }

    private static function utf8CpAt(string $string, int $offset, int $charLen): int
    {
        if ($charLen <= 0) {
            return -1;
        }
        if (1 === $charLen) {
            return \ord(\substr($string, $offset, 1));
        }
        if (2 === $charLen) {
            $b0 = \ord(\substr($string, $offset, 1));
            $b1 = \ord(\substr($string, $offset + 1, 1));

            return (($b0 - 192) * 64) + ($b1 - 128);
        }
        if (3 === $charLen) {
            $b0 = \ord(\substr($string, $offset, 1));
            $b1 = \ord(\substr($string, $offset + 1, 1));
            $b2 = \ord(\substr($string, $offset + 2, 1));

            return (($b0 - 224) * 4096) + (($b1 - 128) * 64) + ($b2 - 128);
        }
        $b0 = \ord(\substr($string, $offset, 1));
        $b1 = \ord(\substr($string, $offset + 1, 1));
        $b2 = \ord(\substr($string, $offset + 2, 1));
        $b3 = \ord(\substr($string, $offset + 3, 1));

        return (($b0 - 240) * 262144) + (($b1 - 128) * 4096) + (($b2 - 128) * 64) + ($b3 - 128);
    }

    private static function encodeUtf8(int $cp): string
    {
        $bytes = self::allBytes();
        if ($cp < 128) {
            return \substr($bytes, $cp, 1);
        }
        if ($cp < 2048) {
            $b1 = $cp - ((int) ($cp / 64) * 64);
            $b0 = (int) ($cp / 64);

            return \substr($bytes, 192 + $b0, 1).\substr($bytes, 128 + $b1, 1);
        }
        if ($cp < 65536) {
            $b2 = $cp - ((int) ($cp / 64) * 64);
            $cp2 = (int) ($cp / 64);
            $b1 = $cp2 - ((int) ($cp2 / 64) * 64);
            $b0 = (int) ($cp2 / 64);

            return \substr($bytes, 224 + $b0, 1).\substr($bytes, 128 + $b1, 1).\substr($bytes, 128 + $b2, 1);
        }
        $b3 = $cp - ((int) ($cp / 64) * 64);
        $cp2 = (int) ($cp / 64);
        $b2 = $cp2 - ((int) ($cp2 / 64) * 64);
        $cp3 = (int) ($cp2 / 64);
        $b1 = $cp3 - ((int) ($cp3 / 64) * 64);
        $b0 = (int) ($cp3 / 64);

        return \substr($bytes, 240 + $b0, 1)
            .\substr($bytes, 128 + $b1, 1)
            .\substr($bytes, 128 + $b2, 1)
            .\substr($bytes, 128 + $b3, 1);
    }

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
}
