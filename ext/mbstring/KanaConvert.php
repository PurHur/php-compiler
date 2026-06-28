<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_kana() UTF-8 core (php-src ext/mbstring/libmbfl/filters/mbfilter_cjk.c; #13099).
 */
final class KanaConvert
{
    private const HAN2ZEN_ALL = 0x00001;
    private const HAN2ZEN_ALPHA = 0x00002;
    private const HAN2ZEN_NUMERIC = 0x00004;
    private const HAN2ZEN_SPACE = 0x00008;
    private const HAN2ZEN_KATAKANA = 0x00010;
    private const HAN2ZEN_HIRAGANA = 0x00020;
    private const HAN2ZEN_SPECIAL = 0x00040;
    private const ZENKAKU_HIRA2KATA = 0x00080;
    private const ZEN2HAN_ALL = 0x00100;
    private const ZEN2HAN_ALPHA = 0x00200;
    private const ZEN2HAN_NUMERIC = 0x00400;
    private const ZEN2HAN_SPACE = 0x00800;
    private const ZEN2HAN_KATAKANA = 0x01000;
    private const ZEN2HAN_HIRAGANA = 0x02000;
    private const ZEN2HAN_SPECIAL = 0x04000;
    private const ZENKAKU_KATA2HIRA = 0x08000;
    private const HAN2ZEN_GLUE = 0x10000;

    /** @var list<string> */
    private const OPTION_FLAGS = [
        'A', 'R', 'N', 'S', 'K', 'H', 'M', 'C',
        'a', 'r', 'n', 's', 'k', 'h', 'm', 'c',
        'V',
    ];

    /** @var list<int> php-src hankana2zenkana_table */
    private const HANKANA2ZENKANA = [
        0x00, 0x02, 0x0C, 0x0D, 0x01, 0xFB, 0xF2, 0xA1, 0xA3, 0xA5,
        0xA7, 0xA9, 0xE3, 0xE5, 0xE7, 0xC3, 0xFC, 0xA2, 0xA4, 0xA6,
        0xA8, 0xAA, 0xAB, 0xAD, 0xAF, 0xB1, 0xB3, 0xB5, 0xB7, 0xB9,
        0xBB, 0xBD, 0xBF, 0xC1, 0xC4, 0xC6, 0xC8, 0xCA, 0xCB, 0xCC,
        0xCD, 0xCE, 0xCF, 0xD2, 0xD5, 0xD8, 0xDB, 0xDE, 0xDF, 0xE0,
        0xE1, 0xE2, 0xE4, 0xE6, 0xE8, 0xE9, 0xEA, 0xEB, 0xEC, 0xED,
        0xEF, 0xF3, 0x9B, 0x9C,
    ];

    /** @var list<int> php-src hankana2zenhira_table */
    private const HANKANA2ZENHIRA = [
        0x00, 0x02, 0x0C, 0x0D, 0x01, 0xFB, 0x92, 0x41, 0x43, 0x45,
        0x47, 0x49, 0x83, 0x85, 0x87, 0x63, 0xFC, 0x42, 0x44, 0x46,
        0x48, 0x4A, 0x4B, 0x4D, 0x4F, 0x51, 0x53, 0x55, 0x57, 0x59,
        0x5B, 0x5D, 0x5F, 0x61, 0x64, 0x66, 0x68, 0x6A, 0x6B, 0x6C,
        0x6D, 0x6E, 0x6F, 0x72, 0x75, 0x78, 0x7B, 0x7E, 0x7F, 0x80,
        0x81, 0x82, 0x84, 0x86, 0x88, 0x89, 0x8A, 0x8B, 0x8C, 0x8D,
        0x8F, 0x93, 0x9B, 0x9C,
    ];

    /** @var list<array{0: int, 1: int}> php-src zenkana2hankana_table */
    private const ZENKANA2HANKANA = [
        [0x67, 0x00], [0x71, 0x00], [0x68, 0x00], [0x72, 0x00], [0x69, 0x00],
        [0x73, 0x00], [0x6A, 0x00], [0x74, 0x00], [0x6B, 0x00], [0x75, 0x00],
        [0x76, 0x00], [0x76, 0x9E], [0x77, 0x00], [0x77, 0x9E], [0x78, 0x00],
        [0x78, 0x9E], [0x79, 0x00], [0x79, 0x9E], [0x7A, 0x00], [0x7A, 0x9E],
        [0x7B, 0x00], [0x7B, 0x9E], [0x7C, 0x00], [0x7C, 0x9E], [0x7D, 0x00],
        [0x7D, 0x9E], [0x7E, 0x00], [0x7E, 0x9E], [0x7F, 0x00], [0x7F, 0x9E],
        [0x80, 0x00], [0x80, 0x9E], [0x81, 0x00], [0x81, 0x9E], [0x6F, 0x00],
        [0x82, 0x00], [0x82, 0x9E], [0x83, 0x00], [0x83, 0x9E], [0x84, 0x00],
        [0x84, 0x9E], [0x85, 0x00], [0x86, 0x00], [0x87, 0x00], [0x88, 0x00],
        [0x89, 0x00], [0x8A, 0x00], [0x8A, 0x9E], [0x8A, 0x9F], [0x8B, 0x00],
        [0x8B, 0x9E], [0x8B, 0x9F], [0x8C, 0x00], [0x8C, 0x9E], [0x8C, 0x9F],
        [0x8D, 0x00], [0x8D, 0x9E], [0x8D, 0x9F], [0x8E, 0x00], [0x8E, 0x9E],
        [0x8E, 0x9F], [0x8F, 0x00], [0x90, 0x00], [0x91, 0x00], [0x92, 0x00],
        [0x93, 0x00], [0x6C, 0x00], [0x94, 0x00], [0x6D, 0x00], [0x95, 0x00],
        [0x6E, 0x00], [0x96, 0x00], [0x97, 0x00], [0x98, 0x00], [0x99, 0x00],
        [0x9A, 0x00], [0x9B, 0x00], [0x9C, 0x00], [0x9C, 0x00], [0x72, 0x00],
        [0x74, 0x00], [0x66, 0x00], [0x9D, 0x00], [0x73, 0x9E],
    ];

    public static function convert(string $input, ?string $option = null, string $encoding = 'UTF-8'): string
    {
        self::assertEncoding($encoding);
        $mode = self::parseOptions($option);

        return self::convertUtf8($input, $mode);
    }

    private static function assertEncoding(string $encoding): void
    {
        $canonical = MbstringEncodingRegistry::resolve($encoding) ?? $encoding;
        if ('UTF-8' !== $canonical && 'ASCII' !== $canonical && '8BIT' !== $canonical) {
            throw new \LogicException(
                'mb_convert_kana() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }
    }

    private static function parseOptions(?string $option): int
    {
        if (null === $option || '' === $option) {
            return self::HAN2ZEN_KATAKANA | self::HAN2ZEN_GLUE;
        }

        $opt = 0;
        $len = \strlen($option);
        for ($i = 0; $i < $len; ++$i) {
            $c = $option[$i];
            if ('A' === $c) {
                $opt |= self::HAN2ZEN_ALL | self::HAN2ZEN_ALPHA | self::HAN2ZEN_NUMERIC;
                continue;
            }
            if ('a' === $c) {
                $opt |= self::ZEN2HAN_ALL | self::ZEN2HAN_ALPHA | self::ZEN2HAN_NUMERIC;
                continue;
            }
            $matched = false;
            foreach (self::OPTION_FLAGS as $index => $flag) {
                if ($c === $flag) {
                    $opt |= 1 << $index;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \ValueError(sprintf(
                    "mb_convert_kana(): Argument #2 (\$mode) contains invalid flag: '%s'",
                    $c
                ));
            }
        }

        self::validateOptionCombinations($opt, $option);

        return $opt;
    }

    private static function validateOptionCombinations(int $opt, string $option): void
    {
        $lower = $opt & 0xFF;
        $upper = ($opt & 0xFF00) >> 8;
        if (0 !== ($lower & $upper)) {
            $bad = $lower & $upper;
            $i = 0;
            while (0 === ($bad & 1)) {
                $bad >>= 1;
                ++$i;
            }
            $flag1 = self::OPTION_FLAGS[$i];
            $flag2 = self::OPTION_FLAGS[$i + 8];
            if (('R' === $flag1 || 'N' === $flag1) && ($opt & self::HAN2ZEN_ALL)) {
                $flag1 = 'A';
            }
            if (('r' === $flag2 || 'n' === $flag2) && ($opt & self::ZEN2HAN_ALL)) {
                $flag2 = 'a';
            }
            throw new \ValueError(sprintf(
                "mb_convert_kana(): Argument #2 (\$mode) must not combine '%s' and '%s' flags",
                $flag1,
                $flag2
            ));
        }

        if (($opt & self::HAN2ZEN_HIRAGANA) && ($opt & self::HAN2ZEN_KATAKANA)) {
            throw new \ValueError("mb_convert_kana(): Argument #2 (\$mode) must not combine 'H' and 'K' flags");
        }

        if ($opt & self::ZEN2HAN_HIRAGANA) {
            if ($opt & self::ZENKAKU_HIRA2KATA) {
                throw new \ValueError("mb_convert_kana(): Argument #2 (\$mode) must not combine 'h' and 'C' flags");
            }
            if ($opt & self::ZENKAKU_KATA2HIRA) {
                throw new \ValueError("mb_convert_kana(): Argument #2 (\$mode) must not combine 'h' and 'c' flags");
            }
        } elseif ($opt & self::ZEN2HAN_KATAKANA) {
            if ($opt & self::ZENKAKU_HIRA2KATA) {
                throw new \ValueError("mb_convert_kana(): Argument #2 (\$mode) must not combine 'k' and 'C' flags");
            }
            if ($opt & self::ZENKAKU_KATA2HIRA) {
                throw new \ValueError("mb_convert_kana(): Argument #2 (\$mode) must not combine 'k' and 'c' flags");
            }
        }
    }

    private static function convertUtf8(string $input, int $mode): string
    {
        $codepoints = self::utf8ToCodepoints($input);
        $out = [];
        $count = \count($codepoints);
        for ($i = 0; $i < $count; ++$i) {
            $next = $i + 1 < $count ? $codepoints[$i + 1] : 0;
            $consumed = false;
            $second = 0;
            $converted = self::convertCodepoint($codepoints[$i], $next, $consumed, $second, $mode);
            $out[] = $converted;
            if ($second) {
                $out[] = $second;
            }
            if ($consumed) {
                ++$i;
            }
        }

        return self::codepointsToUtf8($out);
    }

    /**
     * @return list<int>
     */
    private static function utf8ToCodepoints(string $input): array
    {
        $out = [];
        $charLen = \PHPCompiler\ext\standard\VmString::utf8CharLength($input);
        for ($i = 0; $i < $charLen; ++$i) {
            $out[] = VmMbstring::utf8CharToCodepoint(
                \PHPCompiler\ext\standard\VmString::utf8CharSubstr($input, $i, 1)
            );
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
            $out .= VmMbstring::encodeUtf8Codepoint($cp);
        }

        return $out;
    }

    private static function convertCodepoint(
        int $c,
        int $next,
        ?bool &$consumed,
        ?int &$second,
        int $mode
    ): int {
        if (null !== $consumed) {
            $consumed = false;
        }
        if (null !== $second) {
            $second = 0;
        }

        if (($mode & self::HAN2ZEN_ALL) && $c >= 0x21 && $c <= 0x7D && 0x22 !== $c && 0x27 !== $c && 0x5C !== $c) {
            return $c + 0xFEE0;
        }
        if (($mode & self::HAN2ZEN_ALPHA) && (($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A))) {
            return $c + 0xFEE0;
        }
        if (($mode & self::HAN2ZEN_NUMERIC) && $c >= 0x30 && $c <= 0x39) {
            return $c + 0xFEE0;
        }
        if (($mode & self::HAN2ZEN_SPACE) && 0x20 === $c) {
            return 0x3000;
        }

        if ($mode & (self::HAN2ZEN_KATAKANA | self::HAN2ZEN_HIRAGANA)) {
            $result = self::hanKanaToZen($c, $next, $consumed, $mode);
            if (null !== $result) {
                return $result;
            }
        }

        if ($mode & self::HAN2ZEN_SPECIAL) {
            if (0x5C === $c || 0xA5 === $c) {
                return 0xFFE5;
            }
            if (0x7E === $c || 0x203E === $c) {
                return 0xFFE3;
            }
            if (0x27 === $c) {
                return 0x2019;
            }
            if (0x22 === $c) {
                return 0x201D;
            }
        }

        if ($mode & (self::ZEN2HAN_ALL | self::ZEN2HAN_ALPHA | self::ZEN2HAN_NUMERIC | self::ZEN2HAN_SPACE)) {
            $result = self::zenToHanAscii($c, $mode);
            if (null !== $result) {
                return $result;
            }
        }

        if ($mode & (self::ZEN2HAN_KATAKANA | self::ZEN2HAN_HIRAGANA)) {
            $result = self::zenKanaToHan($c, $second, $mode);
            if (null !== $result) {
                return $result;
            }
        }

        if ($mode & (self::ZENKAKU_HIRA2KATA | self::ZENKAKU_KATA2HIRA)) {
            $result = self::zenKanaCaseSwap($c, $mode);
            if (null !== $result) {
                return $result;
            }
        }

        if ($mode & self::ZEN2HAN_SPECIAL) {
            if (0xFFE5 === $c || 0xFF3C === $c) {
                return 0x5C;
            }
            if (0xFFE3 === $c || 0x203E === $c) {
                return 0x7E;
            }
            if (0x2018 === $c || 0x2019 === $c) {
                return 0x27;
            }
            if (0x201C === $c || 0x201D === $c) {
                return 0x22;
            }
        }

        return $c;
    }

    private static function hanKanaToZen(int $c, int $next, ?bool &$consumed, int $mode): ?int
    {
        if (($mode & self::HAN2ZEN_KATAKANA) && ($mode & self::HAN2ZEN_GLUE) && $c >= 0xFF61 && $c <= 0xFF9F) {
            $n = $c - 0xFF60;
            if ($next >= 0xFF61 && $next <= 0xFF9F) {
                if (0xFF9E === $next && (($n >= 22 && $n <= 36) || ($n >= 42 && $n <= 46))) {
                    if (null !== $consumed) {
                        $consumed = true;
                    }

                    return 0x3001 + self::HANKANA2ZENKANA[$n];
                }
                if (0xFF9E === $next && 19 === $n) {
                    if (null !== $consumed) {
                        $consumed = true;
                    }

                    return 0x30F4;
                }
                if (0xFF9F === $next && $n >= 42 && $n <= 46) {
                    if (null !== $consumed) {
                        $consumed = true;
                    }

                    return 0x3002 + self::HANKANA2ZENKANA[$n];
                }
            }

            return 0x3000 + self::HANKANA2ZENKANA[$n];
        }
        if (($mode & self::HAN2ZEN_HIRAGANA) && ($mode & self::HAN2ZEN_GLUE) && $c >= 0xFF61 && $c <= 0xFF9F) {
            $n = $c - 0xFF60;
            if ($next >= 0xFF61 && $next <= 0xFF9F) {
                if (0xFF9E === $next && (($n >= 22 && $n <= 36) || ($n >= 42 && $n <= 46))) {
                    if (null !== $consumed) {
                        $consumed = true;
                    }

                    return 0x3001 + self::HANKANA2ZENHIRA[$n];
                }
                if (0xFF9F === $next && $n >= 42 && $n <= 46) {
                    if (null !== $consumed) {
                        $consumed = true;
                    }

                    return 0x3002 + self::HANKANA2ZENHIRA[$n];
                }
            }

            return 0x3000 + self::HANKANA2ZENHIRA[$n];
        }
        if (($mode & self::HAN2ZEN_KATAKANA) && $c >= 0xFF61 && $c <= 0xFF9F) {
            return 0x3000 + self::HANKANA2ZENKANA[$c - 0xFF60];
        }
        if (($mode & self::HAN2ZEN_HIRAGANA) && $c >= 0xFF61 && $c <= 0xFF9F) {
            return 0x3000 + self::HANKANA2ZENHIRA[$c - 0xFF60];
        }

        return null;
    }

    private static function zenToHanAscii(int $c, int $mode): ?int
    {
        if (($mode & self::ZEN2HAN_ALL) && $c >= 0xFF01 && $c <= 0xFF5D
            && 0xFF02 !== $c && 0xFF07 !== $c && 0xFF3C !== $c) {
            return $c - 0xFEE0;
        }
        if (($mode & self::ZEN2HAN_ALPHA)
            && (($c >= 0xFF21 && $c <= 0xFF3A) || ($c >= 0xFF41 && $c <= 0xFF5A))) {
            return $c - 0xFEE0;
        }
        if (($mode & self::ZEN2HAN_NUMERIC) && $c >= 0xFF10 && $c <= 0xFF19) {
            return $c - 0xFEE0;
        }
        if (($mode & self::ZEN2HAN_SPACE) && 0x3000 === $c) {
            return 0x20;
        }
        if (($mode & self::ZEN2HAN_ALL) && 0x2212 === $c) {
            return 0x2D;
        }

        return null;
    }

    private static function zenKanaToHan(int $c, ?int &$second, int $mode): ?int
    {
        if (!($mode & (self::ZEN2HAN_KATAKANA | self::ZEN2HAN_HIRAGANA))) {
            return null;
        }

        if (($mode & self::ZEN2HAN_KATAKANA) && $c >= 0x30A1 && $c <= 0x30F4) {
            $n = $c - 0x30A1;
            $pair = self::ZENKANA2HANKANA[$n] ?? [0, 0];
            if (null !== $second && $pair[1]) {
                $second = 0xFF00 + $pair[1];
            }

            return 0xFF00 + $pair[0];
        }
        if (($mode & self::ZEN2HAN_HIRAGANA) && $c >= 0x3041 && $c <= 0x3093) {
            $n = $c - 0x3041;
            $pair = self::ZENKANA2HANKANA[$n] ?? [0, 0];
            if (null !== $second && $pair[1]) {
                $second = 0xFF00 + $pair[1];
            }

            return 0xFF00 + $pair[0];
        }

        return match ($c) {
            0x3001 => 0xFF64,
            0x3002 => 0xFF61,
            0x300C => 0xFF62,
            0x300D => 0xFF63,
            0x309B => 0xFF9E,
            0x309C => 0xFF9F,
            0x30FC => 0xFF70,
            0x30FB => 0xFF65,
            default => null,
        };
    }

    private static function zenKanaCaseSwap(int $c, int $mode): ?int
    {
        if (($mode & self::ZENKAKU_HIRA2KATA)
            && (($c >= 0x3041 && $c <= 0x3093) || 0x309D === $c || 0x309E === $c)) {
            return $c + 0x60;
        }
        if (($mode & self::ZENKAKU_KATA2HIRA)
            && (($c >= 0x30A1 && $c <= 0x30F3) || 0x30FD === $c || 0x30FE === $c)) {
            return $c - 0x60;
        }

        return null;
    }
}
