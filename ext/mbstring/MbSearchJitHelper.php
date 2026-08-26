<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\StringStrpos;

/**
 * mb_strpos() / mb_stripos() / mb_strrpos() / mb_strripos() / mb_strstr() / mb_stristr() /
 * mb_strrchr() / mb_strrichr() for compiled JIT/AOT modules (#34146 / #34158 / #34166 / #34211).
 *
 * Offset helpers return {@see StringStrpos::NOT_FOUND} (-1) on miss so callers can box int|false.
 * {@see strstrArgv} / {@see stristrArgv} / {@see strrchrArgv} / {@see strrichrArgv} return string|false
 * (nullish false → NestedJIT null).
 *
 * NestedJIT must not call {@see VmMbstring::strpos} / {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}
 * — those methods silent-return 0 under thin AOT NestedJIT. Search is inlined with strlen/ord/substr
 * only; UTF-8 width uses range compares (NestedJIT bitwise `&` loops hang on multibyte lead bytes).
 * Case-insensitive APIs use NestedJIT-safe UTF-8 lower (Latin-1 / Greek / Cyrillic; #34703).
 * Runtime encoding validation (#34866) — int-returning assert (string-returning NestedJIT throws SIGSEGV).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strpos()} / stripos / strrpos / strripos / strstr / stristr /
 * strrchr / strrichr
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strpos), mb_stripos, mb_strrpos, mb_strripos, mb_strstr,
 * mb_stristr, mb_strrchr, mb_strrichr
 */
final class MbSearchJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbCaseJitHelper::assertEncodingArgv} (#34866 / #34858).
     *
     * Encoding is Argument #4 for all mb_* search builtins in this unit.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        if ('' === self::canon($encoding)) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #4 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    public static function strposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $encoding = self::canon($encoding);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrpos($haystack, $needle, $offset);
        }

        return self::utf8Strpos($haystack, $needle, $offset);
    }

    /**
     * mb_stripos() — case-insensitive (#34158 leftover of #34146).
     *
     * NestedJIT-safe UTF-8 lower (Latin-1 / Greek / Cyrillic; peer MbConvertCaseJitHelper).
     * Full CaseFolding.txt maps remain on the VM / compile-time fold path via {@see VmMbstring::stripos}.
     */
    public static function striposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $encoding = self::canon($encoding);
        $haystack = self::utf8CaseLower($haystack);
        $needle = self::utf8CaseLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrpos($haystack, $needle, $offset);
        }

        return self::utf8Strpos($haystack, $needle, $offset);
    }

    /**
     * mb_strrpos() — reverse search (#34166 leftover of #34146).
     *
     * Offset semantics match {@see VmMbstring::strrpos} / php-src mb_strrpos.
     */
    public static function strrposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $encoding = self::canon($encoding);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrrpos($haystack, $needle, $offset);
        }

        return self::utf8Strrpos($haystack, $needle, $offset);
    }

    /**
     * mb_strripos() — case-insensitive reverse search (peer of #34158 / #34166).
     *
     * NestedJIT-safe UTF-8 lower; offset semantics match {@see VmMbstring::strripos}.
     */
    public static function strriposArgv(
        string $haystack,
        string $needle,
        int $offset,
        string $encoding
    ): int {
        $encoding = self::canon($encoding);
        $haystack = self::utf8CaseLower($haystack);
        $needle = self::utf8CaseLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::byteStrrpos($haystack, $needle, $offset);
        }

        return self::utf8Strrpos($haystack, $needle, $offset);
    }

    /**
     * mb_strstr() — first occurrence → string|false (#34211 leftover of #34172).
     *
     * @return string|false
     */
    public static function strstrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        $encoding = self::canon($encoding);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrpos($haystack, $needle, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strpos($haystack, $needle, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
    }

    /**
     * mb_stristr() — case-insensitive strstr (peer of #34211 / #34158).
     *
     * NestedJIT-safe UTF-8 lower (Latin-1 / Greek / Cyrillic; peer MbConvertCaseJitHelper).
     *
     * @return string|false
     */
    public static function stristrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        $encoding = self::canon($encoding);
        $hayLower = self::utf8CaseLower($haystack);
        $needleLower = self::utf8CaseLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrpos($hayLower, $needleLower, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strpos($hayLower, $needleLower, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
    }

    /**
     * mb_strrchr() — last occurrence → string|false (peer of #34211 / #20006).
     *
     * @return string|false
     */
    public static function strrchrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        $encoding = self::canon($encoding);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrrpos($haystack, $needle, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strrpos($haystack, $needle, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
    }

    /**
     * mb_strrichr() — case-insensitive strrchr (peer of #34211 / #7015).
     *
     * NestedJIT-safe UTF-8 lower (Latin-1 / Greek / Cyrillic; peer MbConvertCaseJitHelper).
     *
     * @return string|false
     */
    public static function strrichrArgv(
        string $haystack,
        string $needle,
        bool $beforeNeedle,
        string $encoding
    ) {
        $encoding = self::canon($encoding);
        $hayLower = self::utf8CaseLower($haystack);
        $needleLower = self::utf8CaseLower($needle);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $pos = self::byteStrrpos($hayLower, $needleLower, 0);
            if (StringStrpos::NOT_FOUND === $pos) {
                return false;
            }
            if ($beforeNeedle) {
                return \substr($haystack, 0, $pos);
            }

            return \substr($haystack, $pos);
        }

        $pos = self::utf8Strrpos($hayLower, $needleLower, 0);
        if (StringStrpos::NOT_FOUND === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::utf8Substr($haystack, 0, $pos);
        }
        $hayLen = self::utf8Length($haystack);

        return self::utf8Substr($haystack, $pos, $hayLen - $pos);
    }

    /**
     * NestedJIT-safe UTF-8 MB_CASE_LOWER subset (peer {@see MbConvertCaseJitHelper::toLowerCp}).
     *
     * Latin-1 / Greek / Cyrillic length-preserving lowers so char offsets stay aligned with the
     * original haystack for stristr/stripos (#34700 leftover of #34214). Avoid chr()/bitwise
     * masks — NestedJIT hangs or TypeErrors on those.
     */
    private static function utf8CaseLower(string $string): string
    {
        $byteLen = \strlen($string);
        $out = '';
        $i = 0;
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            if (1 === $step) {
                $byte = \ord(\substr($string, $i, 1));
                if ($byte >= 65 && $byte <= 90) {
                    $out = $out.\substr('abcdefghijklmnopqrstuvwxyz', $byte - 65, 1);
                } else {
                    $out = $out.\substr($string, $i, 1);
                }
                $i = $i + 1;
                continue;
            }
            $cp = self::utf8CpAt($string, $i, $step);
            $lower = self::toLowerCp($cp);
            if ($lower === $cp) {
                $out = $out.\substr($string, $i, $step);
            } else {
                $out = $out.self::encodeUtf8($lower);
            }
            $i = $i + $step;
        }

        return $out;
    }

    /** Peer {@see MbConvertCaseJitHelper::toLowerCp} — NestedJIT-safe. */
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
        if (0x3A3 === $cp) {
            return 0x3C3;
        }
        if ($cp >= 0x391 && $cp <= 0x3A9) {
            return $cp + 0x20;
        }
        if (0x401 === $cp) {
            return 0x451;
        }
        if ($cp >= 0x410 && $cp <= 0x42F) {
            return $cp + 0x20;
        }

        return $cp;
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

    private static function byteStrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = \strlen($haystack);
        $needleLen = \strlen($needle);
        $offset = self::normalizeOffset($hayLen, $offset);
        if (0 === $needleLen) {
            return $offset;
        }
        if ($offset > $hayLen - $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $offset;
        while ($pos <= $hayLen - $needleLen) {
            if (\substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function utf8Strpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::utf8Length($haystack);
        $needleLen = self::utf8Length($needle);
        $offset = self::normalizeOffset($hayLen, $offset);
        if (0 === $needleLen) {
            return $offset;
        }
        if ($offset > $hayLen - $needleLen) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $offset;
        while ($pos <= $hayLen - $needleLen) {
            if (self::utf8Substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos + 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function byteStrrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = \strlen($haystack);
        $needleLen = \strlen($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(
                    'mb_strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                );
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart = $maxStart - $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $maxStart;
        while ($pos >= $minStart) {
            if (\substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos - 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function utf8Strrpos(string $haystack, string $needle, int $offset): int
    {
        $hayLen = self::utf8Length($haystack);
        $needleLen = self::utf8Length($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(
                    'mb_strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                );
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart = $maxStart - $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return StringStrpos::NOT_FOUND;
        }
        $pos = $maxStart;
        while ($pos >= $minStart) {
            if (self::utf8Substr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
            $pos = $pos - 1;
        }

        return StringStrpos::NOT_FOUND;
    }

    private static function normalizeOffset(int $hayLen, int $offset): int
    {
        if ($offset < 0) {
            $offset = $offset + $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(
                'mb_strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
            );
        }

        return $offset;
    }

    private static function utf8Length(string $string): int
    {
        $byteLen = \strlen($string);
        $count = 0;
        $i = 0;
        // Cap iterations — NestedJIT must not spin if width miscomputes.
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $step = self::utf8Step($string, $i, $byteLen);
            if ($step < 1) {
                break;
            }
            $i = $i + $step;
            $count = $count + 1;
        }

        return $count;
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

    private static function utf8Substr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = \strlen($string);
        $bytePos = 0;
        $skipped = 0;
        while ($skipped < $charOffset && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $skipped = $skipped + 1;
        }
        $start = $bytePos;
        $taken = 0;
        while ($taken < $charCount && $bytePos < $byteLen) {
            $w = self::utf8Step($string, $bytePos, $byteLen);
            if ($w < 1) {
                break;
            }
            $bytePos = $bytePos + $w;
            $taken = $taken + 1;
        }

        return \substr($string, $start, $bytePos - $start);
    }

    private static function canon(string $encoding): string
    {
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
}
