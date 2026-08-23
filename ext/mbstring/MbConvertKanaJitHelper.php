<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_kana() NestedJIT runtime (#34294 leftover of #13099).
 *
 * Thin AOT NestedJIT SIGSEGVs on deep convert graphs / class-const tables.
 * This unit uses specialized flat converters + local packed tables (proven green).
 * VM SSOT remains {@see KanaConvert}.
 *
 * php-src: ext/mbstring/mbstring.c + libmbfl/filters/mbfilter_cjk.c
 */
final class MbConvertKanaJitHelper
{
    private const HAN2ZEN_KATAKANA = 0x00010;
    private const HAN2ZEN_HIRAGANA = 0x00020;
    private const ZENKAKU_HIRA2KATA = 0x00080;
    private const ZEN2HAN_KATAKANA = 0x01000;
    private const ZEN2HAN_HIRAGANA = 0x02000;
    private const ZENKAKU_KATA2HIRA = 0x08000;
    private const HAN2ZEN_GLUE = 0x10000;

    public static function convertArgv(string $string, string $mode, string $encoding): string
    {
        unset($encoding);

        return self::dispatch($string, $mode);
    }

    public static function convertDefaultArgv(string $string, string $encoding): string
    {
        unset($encoding);

        return self::han2zenKatakana($string, self::HANKANA2ZENKANA_LOCAL());
    }

    private static function dispatch(string $input, string $mode): string
    {
        if ('' === $mode) {
            return $input;
        }
        if ('KV' === $mode || 'VK' === $mode) {
            return self::han2zenKatakana($input, self::HANKANA2ZENKANA_LOCAL());
        }
        if ('HV' === $mode || 'VH' === $mode) {
            return self::han2zenKatakana($input, self::HANKANA2ZENHIRA_LOCAL());
        }
        if ('K' === $mode) {
            return self::han2zenKatakanaNoGlue($input, self::HANKANA2ZENKANA_LOCAL());
        }
        if ('H' === $mode) {
            return self::han2zenKatakanaNoGlue($input, self::HANKANA2ZENHIRA_LOCAL());
        }
        if ('c' === $mode) {
            return self::kata2hira($input);
        }
        if ('C' === $mode) {
            return self::hira2kata($input);
        }
        if ('k' === $mode) {
            return self::zen2hanKatakana($input);
        }
        if ('h' === $mode) {
            return self::zen2hanHiragana($input);
        }

        // Unknown / multi-flag: parse + specialized where possible; else identity (VM fold covers literals).
        $opt = self::parseOptionsBits($mode);
        if ($opt === (self::HAN2ZEN_KATAKANA | self::HAN2ZEN_GLUE)) {
            return self::han2zenKatakana($input, self::HANKANA2ZENKANA_LOCAL());
        }
        if ($opt === (self::HAN2ZEN_HIRAGANA | self::HAN2ZEN_GLUE)) {
            return self::han2zenKatakana($input, self::HANKANA2ZENHIRA_LOCAL());
        }
        if ($opt === self::ZENKAKU_KATA2HIRA) {
            return self::kata2hira($input);
        }
        if ($opt === self::ZENKAKU_HIRA2KATA) {
            return self::hira2kata($input);
        }
        if ($opt === self::ZEN2HAN_KATAKANA) {
            return self::zen2hanKatakana($input);
        }
        if ($opt === self::ZEN2HAN_HIRAGANA) {
            return self::zen2hanHiragana($input);
        }
        if ($opt === self::HAN2ZEN_KATAKANA) {
            return self::han2zenKatakanaNoGlue($input, self::HANKANA2ZENKANA_LOCAL());
        }
        if ($opt === self::HAN2ZEN_HIRAGANA) {
            return self::han2zenKatakanaNoGlue($input, self::HANKANA2ZENHIRA_LOCAL());
        }

        throw new \LogicException(
            'mb_convert_kana() JIT does not lower this mode combination in this compiler build'
        );
    }

    private static function parseOptionsBits(string $option): int
    {
        $opt = 0;
        $len = self::byteLength($option);
        $i = 0;
        while ($i < $len) {
            $c = \substr($option, $i, 1);
            $i = $i + 1;
            if ('K' === $c) {
                $opt = $opt | self::HAN2ZEN_KATAKANA;
            } elseif ('k' === $c) {
                $opt = $opt | self::ZEN2HAN_KATAKANA;
            } elseif ('H' === $c) {
                $opt = $opt | self::HAN2ZEN_HIRAGANA;
            } elseif ('h' === $c) {
                $opt = $opt | self::ZEN2HAN_HIRAGANA;
            } elseif ('C' === $c) {
                $opt = $opt | self::ZENKAKU_HIRA2KATA;
            } elseif ('c' === $c) {
                $opt = $opt | self::ZENKAKU_KATA2HIRA;
            } elseif ('V' === $c) {
                $opt = $opt | self::HAN2ZEN_GLUE;
            } else {
                throw new \ValueError(
                    "mb_convert_kana(): Argument #2 (\$mode) contains invalid flag: '".$c."'"
                );
            }
        }

        return $opt;
    }

    private static function HANKANA2ZENKANA_LOCAL(): string
    {
        return "\x00\x02\x0c\x0d\x01\xfb\xf2\xa1\xa3\xa5\xa7\xa9\xe3\xe5\xe7\xc3\xfc\xa2\xa4\xa6\xa8\xaa\xab\xad\xaf\xb1\xb3\xb5\xb7\xb9\xbb\xbd\xbf\xc1\xc4\xc6\xc8\xca\xcb\xcc\xcd\xce\xcf\xd2\xd5\xd8\xdb\xde\xdf\xe0\xe1\xe2\xe4\xe6\xe8\xe9\xea\xeb\xec\xed\xef\xf3\x9b\x9c";
    }

    private static function HANKANA2ZENHIRA_LOCAL(): string
    {
        return "\x00\x02\x0c\x0d\x01\xfb\x92\x41\x43\x45\x47\x49\x83\x85\x87\x63\xfc\x42\x44\x46\x48\x4a\x4b\x4d\x4f\x51\x53\x55\x57\x59\x5b\x5d\x5f\x61\x64\x66\x68\x6a\x6b\x6c\x6d\x6e\x6f\x72\x75\x78\x7b\x7e\x7f\x80\x81\x82\x84\x86\x88\x89\x8a\x8b\x8c\x8d\x8f\x93\x9b\x9c";
    }

    private static function ZENKANA2HANKANA_LOCAL(): string
    {
        return "\x67\x00\x71\x00\x68\x00\x72\x00\x69\x00\x73\x00\x6a\x00\x74\x00\x6b\x00\x75\x00\x76\x00\x76\x9e\x77\x00\x77\x9e\x78\x00\x78\x9e\x79\x00\x79\x9e\x7a\x00\x7a\x9e\x7b\x00\x7b\x9e\x7c\x00\x7c\x9e\x7d\x00\x7d\x9e\x7e\x00\x7e\x9e\x7f\x00\x7f\x9e\x80\x00\x80\x9e\x81\x00\x81\x9e\x6f\x00\x82\x00\x82\x9e\x83\x00\x83\x9e\x84\x00\x84\x9e\x85\x00\x86\x00\x87\x00\x88\x00\x89\x00\x8a\x00\x8a\x9e\x8a\x9f\x8b\x00\x8b\x9e\x8b\x9f\x8c\x00\x8c\x9e\x8c\x9f\x8d\x00\x8d\x9e\x8d\x9f\x8e\x00\x8e\x9e\x8e\x9f\x8f\x00\x90\x00\x91\x00\x92\x00\x93\x00\x6c\x00\x94\x00\x6d\x00\x95\x00\x6e\x00\x96\x00\x97\x00\x98\x00\x99\x00\x9a\x00\x9b\x00\x9c\x00\x9c\x00\x72\x00\x74\x00\x66\x00\x9d\x00\x73\x9e";
    }

    private static function han2zenKatakana(string $input, string $table): string
    {
        $out = '';
        $len = self::byteLength($input);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($input, $i);
            $c = self::utf8CpAt($input, $i, $charLen);
            $next = 0;
            $nextLen = 0;
            if ($i + $charLen < $len) {
                $nextLen = self::utf8ByteLenAt($input, $i + $charLen);
                $next = self::utf8CpAt($input, $i + $charLen, $nextLen);
            }
            $consumed = 0;
            if ($c >= 0xFF61 && $c <= 0xFF9F) {
                $n = $c - 0xFF60;
                if ($next >= 0xFF61 && $next <= 0xFF9F) {
                    if (0xFF9E === $next && (($n >= 22 && $n <= 36) || ($n >= 42 && $n <= 46))) {
                        $c = 0x3001 + \ord(\substr($table, $n, 1));
                        $consumed = 1;
                    } elseif (0xFF9E === $next && 19 === $n) {
                        $c = 0x30F4;
                        $consumed = 1;
                    } elseif (0xFF9F === $next && $n >= 42 && $n <= 46) {
                        $c = 0x3002 + \ord(\substr($table, $n, 1));
                        $consumed = 1;
                    } else {
                        $c = 0x3000 + \ord(\substr($table, $n, 1));
                    }
                } else {
                    $c = 0x3000 + \ord(\substr($table, $n, 1));
                }
            }
            $out = $out.self::encodeUtf8($c);
            $i = $i + $charLen;
            if (0 !== $consumed) {
                $i = $i + $nextLen;
            }
        }

        return $out;
    }

    private static function han2zenKatakanaNoGlue(string $input, string $table): string
    {
        $out = '';
        $len = self::byteLength($input);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($input, $i);
            $c = self::utf8CpAt($input, $i, $charLen);
            if ($c >= 0xFF61 && $c <= 0xFF9F) {
                $c = 0x3000 + \ord(\substr($table, $c - 0xFF60, 1));
            }
            $out = $out.self::encodeUtf8($c);
            $i = $i + $charLen;
        }

        return $out;
    }

    private static function kata2hira(string $input): string
    {
        $out = '';
        $len = self::byteLength($input);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($input, $i);
            $c = self::utf8CpAt($input, $i, $charLen);
            if (($c >= 0x30A1 && $c <= 0x30F3) || 0x30FD === $c || 0x30FE === $c) {
                $c = $c - 0x60;
            }
            $out = $out.self::encodeUtf8($c);
            $i = $i + $charLen;
        }

        return $out;
    }

    private static function hira2kata(string $input): string
    {
        $out = '';
        $len = self::byteLength($input);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($input, $i);
            $c = self::utf8CpAt($input, $i, $charLen);
            if (($c >= 0x3041 && $c <= 0x3093) || 0x309D === $c || 0x309E === $c) {
                $c = $c + 0x60;
            }
            $out = $out.self::encodeUtf8($c);
            $i = $i + $charLen;
        }

        return $out;
    }

    private static function zen2hanKatakana(string $input): string
    {
        return self::zen2hanKana($input, true);
    }

    private static function zen2hanHiragana(string $input): string
    {
        return self::zen2hanKana($input, false);
    }

    private static function zen2hanKana(string $input, bool $katakana): string
    {
        $table = self::ZENKANA2HANKANA_LOCAL();
        $out = '';
        $len = self::byteLength($input);
        $i = 0;
        while ($i < $len) {
            $charLen = self::utf8ByteLenAt($input, $i);
            $c = self::utf8CpAt($input, $i, $charLen);
            $second = 0;
            if ($katakana && $c >= 0x30A1 && $c <= 0x30F4) {
                $n = $c - 0x30A1;
                $c = 0xFF00 + \ord(\substr($table, $n * 2, 1));
                $p1 = \ord(\substr($table, $n * 2 + 1, 1));
                if (0 !== $p1) {
                    $second = 0xFF00 + $p1;
                }
            } elseif ((!$katakana) && $c >= 0x3041 && $c <= 0x3093) {
                $n = $c - 0x3041;
                $c = 0xFF00 + \ord(\substr($table, $n * 2, 1));
                $p1 = \ord(\substr($table, $n * 2 + 1, 1));
                if (0 !== $p1) {
                    $second = 0xFF00 + $p1;
                }
            } elseif (0x3001 === $c) {
                $c = 0xFF64;
            } elseif (0x3002 === $c) {
                $c = 0xFF61;
            } elseif (0x300C === $c) {
                $c = 0xFF62;
            } elseif (0x300D === $c) {
                $c = 0xFF63;
            } elseif (0x309B === $c) {
                $c = 0xFF9E;
            } elseif (0x309C === $c) {
                $c = 0xFF9F;
            } elseif (0x30FC === $c) {
                $c = 0xFF70;
            } elseif (0x30FB === $c) {
                $c = 0xFF65;
            }
            $out = $out.self::encodeUtf8($c);
            if (0 !== $second) {
                $out = $out.self::encodeUtf8($second);
            }
            $i = $i + $charLen;
        }

        return $out;
    }

    private static function byteLength(string $string): int
    {
        $n = 0;
        while (isset($string[$n])) {
            $n = $n + 1;
            if ($n > 1048576) {
                break;
            }
        }

        return $n;
    }

    private static function utf8ByteLenAt(string $string, int $offset): int
    {
        $b = \ord(\substr($string, $offset, 1));
        if ($b < 128) {
            return 1;
        }
        if ($b < 224) {
            return 2;
        }
        if ($b < 240) {
            return 3;
        }

        return 4;
    }

    private static function utf8CpAt(string $string, int $offset, int $charLen): int
    {
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
