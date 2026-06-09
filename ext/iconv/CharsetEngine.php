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
            default => false,
        };
        if (false === $utf8) {
            return false;
        }

        return match ($toCanon) {
            'UTF-8' => $utf8,
            'ISO-8859-1' => self::utf8ToLatin1($utf8, $flags),
            'ASCII' => self::utf8ToAscii($utf8, $flags),
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
        if (!($flags & self::FLAG_IGNORE)) {
            $len = \strlen($latin1);
            for ($i = 0; $i < $len; ++$i) {
                if (\ord($latin1[$i]) > 0x7F) {
                    return false;
                }
            }

            return $latin1;
        }

        $out = '';
        $len = \strlen($latin1);
        for ($i = 0; $i < $len; ++$i) {
            if (\ord($latin1[$i]) <= 0x7F) {
                $out .= $latin1[$i];
            }
        }

        return $out;
    }
}
