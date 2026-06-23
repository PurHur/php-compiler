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
        $from = self::parseEncodingSpec($fromEncoding);
        $to = self::parseEncodingSpec($toEncoding);
        if (null === $from || null === $to) {
            return false;
        }

        [$fromCanon, $fromFlags] = $from;
        [$toCanon, $toFlags] = $to;
        $flags = $fromFlags | $toFlags;

        if ($fromCanon === $toCanon) {
            return $input;
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

                    return false;
                }
                $cp = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                if ($cp > 0xFF) {
                    if ($flags & self::FLAG_IGNORE) {
                        $i += 2;

                        continue;
                    }

                    return false;
                }
                $out .= \chr($cp);
                $i += 2;

                continue;
            }
            if ($flags & self::FLAG_IGNORE) {
                ++$i;

                continue;
            }

            return false;
        }

        return $out;
    }

    private static function asciiToUtf8(string $input, int $flags): string|false
    {
        if (!($flags & self::FLAG_IGNORE)) {
            $len = \strlen($input);
            for ($i = 0; $i < $len; ++$i) {
                if (\ord($input[$i]) > 0x7F) {
                    return false;
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
        $latin1 = self::utf8ToLatin1($input, $flags);
        if (false === $latin1) {
            return false;
        }

        $out = '';
        $len = \strlen($latin1);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($latin1[$i]);
            if ($byte <= 0x7F) {
                $out .= $latin1[$i];

                continue;
            }
            if ($flags & self::FLAG_TRANSLIT) {
                $translit = self::transliterateLatin1($byte);
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

                    return null;
                }
                $out[] = (($b & 0x1F) << 6) | ($b2 & 0x3F);
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

                    return null;
                }
                $out[] = (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
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

                    return null;
                }
                $out[] = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                $i += 4;
                continue;
            }
            if ($flags & self::FLAG_IGNORE) {
                ++$i;
                continue;
            }

            return null;
        }

        return $out;
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

    private static function transliterateLatin1(int $byte): ?string
    {
        static $map = [
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

        return $map[$byte] ?? null;
    }
}
