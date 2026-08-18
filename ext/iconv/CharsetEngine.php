<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * Native charset conversion without host ext/iconv (issue #6251, pairs #3222).
 *
 * php-src: ext/iconv/iconv.c — subset for bootstrap (ISO-8859-1, UTF-8, ASCII).
 */
final class CharsetEngine
{
    public const FLAG_IGNORE = 1;

    public const FLAG_TRANSLIT = 2;

    public const ERROR_NONE = 0;

    public const ERROR_ILLEGAL = 1;

    public const ERROR_INCOMPLETE = 2;

    private static int $lastError = self::ERROR_NONE;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    private static function failUtf8(int $error): false
    {
        self::$lastError = $error;

        return false;
    }

    /** @return null Always null — utf8Codepoints() return type is ?array */
    private static function failCodepoints(int $error): null
    {
        self::$lastError = $error;

        return null;
    }

    /**
     * @return array{0: string, 1: int}|null [canonical encoding, flags]
     */
    public static function parseEncodingSpec(string $spec): ?array
    {
        $flags = 0;
        $name = $spec;
        if (str_contains($spec, '//')) {
            [$name, $suffix] = explode('//', $spec, 2);
            $suffix = strtoupper($suffix);
            if (str_contains($suffix, 'IGNORE')) {
                $flags |= self::FLAG_IGNORE;
            }
            if (str_contains($suffix, 'TRANSLIT')) {
                $flags |= self::FLAG_TRANSLIT;
            }
        }

        $canonical = self::canonicalize($name);
        if (null === $canonical) {
            return null;
        }

        return [$canonical, $flags];
    }

    public static function canonicalize(string $name): ?string
    {
        $normalized = strtoupper(trim($name));
        $normalized = str_replace(['-', '_', ' '], '', $normalized);

        return match ($normalized) {
            'UTF8' => 'UTF-8',
            'UTF16LE' => 'UTF-16LE',
            'UTF16BE' => 'UTF-16BE',
            'ISO88591', 'LATIN1' => 'ISO-8859-1',
            'ASCII', 'USASCII' => 'ASCII',
            default => null,
        };
    }

    public static function convert(string $fromEncoding, string $toEncoding, string $input): string|false
    {
        self::$lastError = self::ERROR_NONE;
        $from = self::parseEncodingSpec($fromEncoding);
        $to = self::parseEncodingSpec($toEncoding);
        if (null === $from || null === $to) {
            return false;
        }

        [$fromCanon, $fromFlags] = $from;
        [$toCanon, $toFlags] = $to;
        $flags = $fromFlags | $toFlags;

        // php-src iconv.c: same charset still validates the input sequence.
        // Skipping that short-circuits illegal UTF-8/ASCII as a passthrough (#25167).
        if ($fromCanon === $toCanon) {
            return self::applySameEncodingFlags($fromCanon, $input, $flags);
        }

        $utf8 = match ($fromCanon) {
            'UTF-8' => $input,
            'ISO-8859-1' => self::latin1ToUtf8($input),
            'ASCII' => self::asciiToUtf8($input, $flags),
            'UTF-16LE' => self::utf16leToUtf8($input, $flags),
            'UTF-16BE' => self::utf16beToUtf8($input, $flags),
            default => false,
        };
        if (false === $utf8) {
            return false;
        }

        return match ($toCanon) {
            'UTF-8' => $utf8,
            'ISO-8859-1' => self::utf8ToLatin1($utf8, $flags),
            'ASCII' => self::utf8ToAscii($utf8, $flags),
            'UTF-16LE' => self::utf8ToUtf16le($utf8, $flags),
            'UTF-16BE' => self::utf8ToUtf16be($utf8, $flags),
            default => false,
        };
    }

    /**
     * Same canonical encoding — validate (and optionally //IGNORE/TRANSLIT) per php-src iconv.c.
     */
    private static function applySameEncodingFlags(string $canon, string $input, int $flags): string|false
    {
        return match ($canon) {
            'UTF-8' => self::normalizeUtf8($input, $flags),
            // High bytes are illegal in ASCII even when from==to (#25167).
            'ASCII' => self::asciiToUtf8($input, $flags),
            'ISO-8859-1' => $input,
            'UTF-16LE' => self::normalizeUtf16($input, $flags, false),
            'UTF-16BE' => self::normalizeUtf16($input, $flags, true),
            default => $input,
        };
    }

    private static function normalizeUtf16(string $input, int $flags, bool $be): string|false
    {
        $utf8 = self::utf16ToUtf8($input, $flags, $be);
        if (false === $utf8) {
            return false;
        }
        if (0 === ($flags & (self::FLAG_IGNORE | self::FLAG_TRANSLIT))) {
            return $input;
        }

        return self::utf8ToUtf16($utf8, $flags, $be);
    }

    private static function normalizeUtf8(string $input, int $flags): string|false
    {
        if (0 === ($flags & (self::FLAG_IGNORE | self::FLAG_TRANSLIT))) {
            return null === self::utf8Codepoints($input, 0) ? false : $input;
        }
        $codepoints = self::utf8Codepoints($input, $flags);
        if (null === $codepoints) {
            return false;
        }

        return self::codepointsToUtf8($codepoints);
    }

    public static function latin1ToUtf8(string $input): string
    {
        $out = '';
        $len = \strlen($input);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($input[$i]);
            if ($byte < 0x80) {
                $out .= $input[$i];
            } else {
                $out .= \chr(0xC0 | ($byte >> 6)).\chr(0x80 | ($byte & 0x3F));
            }
        }

        return $out;
    }

    public static function utf8ToLatin1(string $input, int $flags = 0): string|false
    {
        $out = '';
        $len = \strlen($input);
        $i = 0;
        while ($i < $len) {
            $b = \ord($input[$i]);
            if ($b < 0x80) {
                $out .= $input[$i];
                ++$i;

                continue;
            }
            if (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $b2 = \ord($input[$i + 1]);
                if (0x80 !== ($b2 & 0xC0)) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;

                        continue;
                    }

                    return self::failUtf8(self::ERROR_ILLEGAL);
                }
                $cp = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                if ($cp > 0xFF) {
                    if ($flags & self::FLAG_IGNORE) {
                        $i += 2;

                        continue;
                    }

                    return self::failUtf8(self::ERROR_ILLEGAL);
                }
                $out .= \chr($cp);
                $i += 2;

                continue;
            }
            if ($flags & self::FLAG_IGNORE) {
                ++$i;

                continue;
            }

            return self::failUtf8(self::classifyUtf8Failure($input, $i, $len));
        }

        return $out;
    }

    private static function asciiToUtf8(string $input, int $flags): string|false
    {
        if (!($flags & self::FLAG_IGNORE)) {
            $len = \strlen($input);
            for ($i = 0; $i < $len; ++$i) {
                if (\ord($input[$i]) > 0x7F) {
                    return self::failUtf8(self::ERROR_ILLEGAL);
                }
            }

            return $input;
        }

        $out = '';
        $len = \strlen($input);
        for ($i = 0; $i < $len; ++$i) {
            if (\ord($input[$i]) <= 0x7F) {
                $out .= $input[$i];
            }
        }

        return $out;
    }

    private static function utf8ToAscii(string $input, int $flags): string|false
    {
        $codepoints = self::utf8Codepoints($input, $flags);
        if (null === $codepoints) {
            return false;
        }

        $out = '';
        foreach ($codepoints as $cp) {
            if ($cp <= 0x7F) {
                $out .= \chr($cp);

                continue;
            }
            if ($flags & self::FLAG_TRANSLIT) {
                $translit = self::transliterateCodepoint($cp);
                if (null !== $translit) {
                    $out .= $translit;

                    continue;
                }
            }
            if ($flags & self::FLAG_IGNORE) {
                continue;
            }

            return false;
        }

        return $out;
    }

    private static function utf8ToUtf16le(string $input, int $flags): string|false
    {
        return self::utf8ToUtf16($input, $flags, false);
    }

    private static function utf8ToUtf16be(string $input, int $flags): string|false
    {
        return self::utf8ToUtf16($input, $flags, true);
    }

    private static function utf8ToUtf16(string $input, int $flags, bool $be): string|false
    {
        $codepoints = self::utf8Codepoints($input, $flags);
        if (null === $codepoints) {
            return false;
        }
        $out = '';
        foreach ($codepoints as $cp) {
            $units = self::codepointToUtf16Units($cp);
            if (null === $units) {
                if ($flags & self::FLAG_IGNORE) {
                    continue;
                }

                return false;
            }
            foreach ($units as $unit) {
                $out .= $be
                    ? \chr(($unit >> 8) & 0xFF).\chr($unit & 0xFF)
                    : \chr($unit & 0xFF).\chr(($unit >> 8) & 0xFF);
            }
        }

        return $out;
    }

    private static function utf16leToUtf8(string $input, int $flags): string|false
    {
        return self::utf16ToUtf8($input, $flags, false);
    }

    private static function utf16beToUtf8(string $input, int $flags): string|false
    {
        return self::utf16ToUtf8($input, $flags, true);
    }

    private static function utf16ToUtf8(string $input, int $flags, bool $be): string|false
    {
        $len = \strlen($input);
        if (0 !== $len % 2) {
            if ($flags & self::FLAG_IGNORE) {
                $input = \substr($input, 0, $len - 1);
                $len -= 1;
            } else {
                return false;
            }
        }
        $codepoints = [];
        for ($i = 0; $i < $len; $i += 2) {
            $unit = $be
                ? (\ord($input[$i]) << 8) | \ord($input[$i + 1])
                : \ord($input[$i]) | (\ord($input[$i + 1]) << 8);
            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                if ($i + 3 >= $len) {
                    if ($flags & self::FLAG_IGNORE) {
                        continue;
                    }

                    return false;
                }
                $low = $be
                    ? (\ord($input[$i + 2]) << 8) | \ord($input[$i + 3])
                    : \ord($input[$i + 2]) | (\ord($input[$i + 3]) << 8);
                if ($low < 0xDC00 || $low > 0xDFFF) {
                    if ($flags & self::FLAG_IGNORE) {
                        continue;
                    }

                    return false;
                }
                $codepoints[] = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                $i += 2;
                continue;
            }
            if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                if ($flags & self::FLAG_IGNORE) {
                    continue;
                }

                return false;
            }
            $codepoints[] = $unit;
        }

        return self::codepointsToUtf8($codepoints);
    }

    /**
     * @return list<int>|null
     */
    private static function utf8Codepoints(string $input, int $flags): ?array
    {
        $out = [];
        $len = \strlen($input);
        for ($i = 0; $i < $len;) {
            $b = \ord($input[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                ++$i;
                continue;
            }
            if (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $b2 = \ord($input[$i + 1]);
                if (0x80 !== ($b2 & 0xC0)) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $cp = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                // Reject overlong 2-byte forms (C0/C1…) — glibc iconv / php-src iconv.c.
                if ($cp < 0x80) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $out[] = $cp;
                $i += 2;
                continue;
            }
            if (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $b2 = \ord($input[$i + 1]);
                $b3 = \ord($input[$i + 2]);
                if (0x80 !== ($b2 & 0xC0) || 0x80 !== ($b3 & 0xC0)) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $cp = (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                // Overlong 3-byte or UTF-16 surrogate half.
                if ($cp < 0x800 || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $out[] = $cp;
                $i += 3;
                continue;
            }
            if (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $b2 = \ord($input[$i + 1]);
                $b3 = \ord($input[$i + 2]);
                $b4 = \ord($input[$i + 3]);
                if (0x80 !== ($b2 & 0xC0) || 0x80 !== ($b3 & 0xC0) || 0x80 !== ($b4 & 0xC0)) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $cp = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                // Overlong 4-byte only — glibc iconv may still accept cp > U+10FFFF.
                if ($cp < 0x10000) {
                    if ($flags & self::FLAG_IGNORE) {
                        ++$i;
                        continue;
                    }

                    return self::failCodepoints(self::ERROR_ILLEGAL);
                }
                $out[] = $cp;
                $i += 4;
                continue;
            }
            if ($flags & self::FLAG_IGNORE) {
                ++$i;
                continue;
            }

            return self::failCodepoints(self::classifyUtf8Failure($input, $i, $len));
        }

        return $out;
    }

    private static function classifyUtf8Failure(string $input, int $i, int $len): int
    {
        $b = \ord($input[$i]);
        if (($b & 0xC0) === 0x80) {
            return self::ERROR_ILLEGAL;
        }
        $need = match (true) {
            ($b & 0x80) === 0 => 0,
            ($b & 0xE0) === 0xC0 => 1,
            ($b & 0xF0) === 0xE0 => 2,
            ($b & 0xF8) === 0xF0 => 3,
            default => -1,
        };
        if ($need >= 0 && $i + $need >= $len) {
            return self::ERROR_INCOMPLETE;
        }

        return self::ERROR_ILLEGAL;
    }

    /**
     * @param list<int> $codepoints
     */
    private static function codepointsToUtf8(array $codepoints): string
    {
        $out = '';
        foreach ($codepoints as $cp) {
            if ($cp <= 0x7F) {
                $out .= \chr($cp);
            } elseif ($cp <= 0x7FF) {
                $out .= \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
            } elseif ($cp <= 0xFFFF) {
                $out .= \chr(0xE0 | ($cp >> 12))
                    .\chr(0x80 | (($cp >> 6) & 0x3F))
                    .\chr(0x80 | ($cp & 0x3F));
            } else {
                $out .= \chr(0xF0 | ($cp >> 18))
                    .\chr(0x80 | (($cp >> 12) & 0x3F))
                    .\chr(0x80 | (($cp >> 6) & 0x3F))
                    .\chr(0x80 | ($cp & 0x3F));
            }
        }

        return $out;
    }

    /** @return list<int>|null */
    private static function codepointToUtf16Units(int $cp): ?array
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if ($cp < 0x10000) {
            return [$cp];
        }
        $cp -= 0x10000;

        return [0xD800 | (($cp >> 10) & 0x3FF), 0xDC00 | ($cp & 0x3FF)];
    }

    /**
     * glibc/libiconv //TRANSLIT subset — Latin-1 accents + common UTF-8 symbols (php-src iconv.c).
     */
    private static function transliterateCodepoint(int $cp): ?string
    {
        static $latin1 = [
            0xC0 => 'A', 0xC1 => 'A', 0xC2 => 'A', 0xC3 => 'A', 0xC4 => 'A', 0xC5 => 'A',
            0xC6 => 'AE', 0xC7 => 'C',
            0xC8 => 'E', 0xC9 => 'E', 0xCA => 'E', 0xCB => 'E',
            0xCC => 'I', 0xCD => 'I', 0xCE => 'I', 0xCF => 'I',
            0xD0 => 'D', 0xD1 => 'N',
            0xD2 => 'O', 0xD3 => 'O', 0xD4 => 'O', 0xD5 => 'O', 0xD6 => 'O',
            0xD8 => 'O',
            0xD9 => 'U', 0xDA => 'U', 0xDB => 'U', 0xDC => 'U',
            0xDD => 'Y', 0xDE => 'TH', 0xDF => 'ss',
            0xE0 => 'a', 0xE1 => 'a', 0xE2 => 'a', 0xE3 => 'a', 0xE4 => 'a', 0xE5 => 'a',
            0xE6 => 'ae', 0xE7 => 'c',
            0xE8 => 'e', 0xE9 => 'e', 0xEA => 'e', 0xEB => 'e',
            0xEC => 'i', 0xED => 'i', 0xEE => 'i', 0xEF => 'i',
            0xF0 => 'd', 0xF1 => 'n',
            0xF2 => 'o', 0xF3 => 'o', 0xF4 => 'o', 0xF5 => 'o', 0xF6 => 'o',
            0xF8 => 'o',
            0xF9 => 'u', 0xFA => 'u', 0xFB => 'u', 0xFC => 'u',
            0xFD => 'y', 0xFE => 'th', 0xFF => 'y',
        ];
        static $unicode = [
            0x00A0 => ' ',   // NBSP
            0x00A3 => 'GBP', // pound
            0x00A5 => 'JPY', // yen
            0x00A9 => '(C)', // copyright
            0x00B0 => '?',   // degree — glibc on pinned image
            0x2013 => '-',   // en dash
            0x2014 => '--',  // em dash
            0x2026 => '...', // ellipsis
            0x20AC => 'EUR', // euro (#32103)
        ];

        if ($cp <= 0xFF) {
            return $latin1[$cp] ?? ($unicode[$cp] ?? null);
        }

        return $unicode[$cp] ?? null;
    }
}
