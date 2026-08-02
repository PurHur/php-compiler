<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base64_encode()/base64_decode() for compiled JIT/AOT modules (#17234, #17249, #26890).
 *
 * Logic mirrors {@see VmString}::base64_encode / base64_decode — self-contained (no VmString call)
 * so NestedJIT helper units are not ExternalMethod-stubbed (#16075 / peer Bin2hexJitHelper #20452,
 * StrRot13 #26868). Byte ordinal / emit via match tables (no native ord()/chr()/strlen()).
 *
 * php-src: ext/standard/base64.c
 */
final class Base64JitHelper
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public static function encodeArgv(string $data): string
    {
        $len = 0;
        while (isset($data[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }
        $alphabet = self::ALPHABET;
        $out = '';
        for ($i = 0; $i < $len; $i += 3) {
            $b0 = self::byteOrd($data[$i]);
            $b1 = ($i + 1 < $len) ? self::byteOrd($data[$i + 1]) : 0;
            $b2 = ($i + 2 < $len) ? self::byteOrd($data[$i + 2]) : 0;
            $n = ($b0 << 16) | ($b1 << 8) | $b2;
            $out .= $alphabet[($n >> 18) & 63];
            $out .= $alphabet[($n >> 12) & 63];
            if ($i + 1 < $len) {
                $out .= $alphabet[($n >> 6) & 63];
            } else {
                $out .= '=';
            }
            if ($i + 2 < $len) {
                $out .= $alphabet[$n & 63];
            } else {
                $out .= '=';
            }
        }

        return $out;
    }

    /**
     * Non-strict RFC 4648 decode for the string ABI bridge ($strict=false).
     * Invalid input that would yield false under Zend becomes "" so the __string__* ABI stays honest.
     */
    public static function decodeArgv(string $data): string
    {
        $result = self::decodeImpl($data, false);

        return false === $result ? '' : $result;
    }

    /**
     * Append-only bit accumulator (no `$str[$i] =` mutation; no negative bit cursor —
     * NestedJIT treats negative `$valb` as 0 and emits a spurious NUL, #26890).
     *
     * @return string|false
     */
    private static function decodeImpl(string $data, bool $strict)
    {
        $len = 0;
        while (isset($data[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }
        $out = '';
        $acc = 0;
        $nbits = 0;
        $i = 0;
        $padding = 0;
        for ($pos = 0; $pos < $len; ++$pos) {
            $ch = $data[$pos];
            if ('=' === $ch) {
                ++$padding;
                continue;
            }
            $d = self::reverseChar($ch);
            if (!$strict) {
                if ($d >= 64) {
                    continue;
                }
            } else {
                if (64 === $d) {
                    continue;
                }
                if (65 === $d || $padding > 0) {
                    return false;
                }
            }
            $acc = ($acc << 6) | $d;
            $nbits += 6;
            ++$i;
            if ($nbits >= 8) {
                $nbits -= 8;
                $out .= self::byteAt(($acc >> $nbits) & 0xFF);
                $acc = self::lowBits($acc, $nbits);
            }
        }
        if ($strict && 1 === $i % 4) {
            return false;
        }
        if ($strict && $padding > 0 && ($padding > 2 || 0 !== ($i + $padding) % 4)) {
            return false;
        }

        return $out;
    }

    /** Keep the low $n bits of $acc (NestedJIT-safe; $n is 0..7). */
    private static function lowBits(int $acc, int $n): int
    {
        return match ($n) {
            0 => 0,
            1 => $acc & 1,
            2 => $acc & 3,
            3 => $acc & 7,
            4 => $acc & 15,
            5 => $acc & 31,
            6 => $acc & 63,
            7 => $acc & 127,
            default => $acc,
        };
    }

    /** php-src base64_reverse_table: 64 whitespace, 65 invalid, 0..63 digit (NestedJIT-safe; no negatives). */
    private static function reverseChar(string $ch): int
    {
        return match ($ch) {
            "\t", "\n", "\r", ' ' => 64,
            'A' => 0,
            'B' => 1,
            'C' => 2,
            'D' => 3,
            'E' => 4,
            'F' => 5,
            'G' => 6,
            'H' => 7,
            'I' => 8,
            'J' => 9,
            'K' => 10,
            'L' => 11,
            'M' => 12,
            'N' => 13,
            'O' => 14,
            'P' => 15,
            'Q' => 16,
            'R' => 17,
            'S' => 18,
            'T' => 19,
            'U' => 20,
            'V' => 21,
            'W' => 22,
            'X' => 23,
            'Y' => 24,
            'Z' => 25,
            'a' => 26,
            'b' => 27,
            'c' => 28,
            'd' => 29,
            'e' => 30,
            'f' => 31,
            'g' => 32,
            'h' => 33,
            'i' => 34,
            'j' => 35,
            'k' => 36,
            'l' => 37,
            'm' => 38,
            'n' => 39,
            'o' => 40,
            'p' => 41,
            'q' => 42,
            'r' => 43,
            's' => 44,
            't' => 45,
            'u' => 46,
            'v' => 47,
            'w' => 48,
            'x' => 49,
            'y' => 50,
            'z' => 51,
            '0' => 52,
            '1' => 53,
            '2' => 54,
            '3' => 55,
            '4' => 56,
            '5' => 57,
            '6' => 58,
            '7' => 59,
            '8' => 60,
            '9' => 61,
            '+' => 62,
            '/' => 63,
            default => 65,
        };
    }

    /** NestedJIT-safe byte ordinal (#20452). */
    private static function byteOrd(string $byte): int
    {
        for ($code = 0; $code < 256; ++$code) {
            if ($byte === self::byteAt($code)) {
                return $code;
            }
        }

        return 0;
    }

    private static function byteAt(int $code): string
    {
        return match ($code) {
            0 => "\0",
            1 => "\x01",
            2 => "\x02",
            3 => "\x03",
            4 => "\x04",
            5 => "\x05",
            6 => "\x06",
            7 => "\x07",
            8 => "\x08",
            9 => "\x09",
            10 => "\x0a",
            11 => "\x0b",
            12 => "\x0c",
            13 => "\x0d",
            14 => "\x0e",
            15 => "\x0f",
            16 => "\x10",
            17 => "\x11",
            18 => "\x12",
            19 => "\x13",
            20 => "\x14",
            21 => "\x15",
            22 => "\x16",
            23 => "\x17",
            24 => "\x18",
            25 => "\x19",
            26 => "\x1a",
            27 => "\x1b",
            28 => "\x1c",
            29 => "\x1d",
            30 => "\x1e",
            31 => "\x1f",
            32 => ' ',
            33 => '!',
            34 => "\"",
            35 => '#',
            36 => "\$",
            37 => '%',
            38 => '&',
            39 => "'",
            40 => '(',
            41 => ')',
            42 => '*',
            43 => '+',
            44 => ',',
            45 => '-',
            46 => '.',
            47 => '/',
            48 => '0',
            49 => '1',
            50 => '2',
            51 => '3',
            52 => '4',
            53 => '5',
            54 => '6',
            55 => '7',
            56 => '8',
            57 => '9',
            58 => ':',
            59 => ';',
            60 => '<',
            61 => '=',
            62 => '>',
            63 => '?',
            64 => '@',
            65 => 'A',
            66 => 'B',
            67 => 'C',
            68 => 'D',
            69 => 'E',
            70 => 'F',
            71 => 'G',
            72 => 'H',
            73 => 'I',
            74 => 'J',
            75 => 'K',
            76 => 'L',
            77 => 'M',
            78 => 'N',
            79 => 'O',
            80 => 'P',
            81 => 'Q',
            82 => 'R',
            83 => 'S',
            84 => 'T',
            85 => 'U',
            86 => 'V',
            87 => 'W',
            88 => 'X',
            89 => 'Y',
            90 => 'Z',
            91 => '[',
            92 => "\\",
            93 => ']',
            94 => '^',
            95 => '_',
            96 => '`',
            97 => 'a',
            98 => 'b',
            99 => 'c',
            100 => 'd',
            101 => 'e',
            102 => 'f',
            103 => 'g',
            104 => 'h',
            105 => 'i',
            106 => 'j',
            107 => 'k',
            108 => 'l',
            109 => 'm',
            110 => 'n',
            111 => 'o',
            112 => 'p',
            113 => 'q',
            114 => 'r',
            115 => 's',
            116 => 't',
            117 => 'u',
            118 => 'v',
            119 => 'w',
            120 => 'x',
            121 => 'y',
            122 => 'z',
            123 => '{',
            124 => '|',
            125 => '}',
            126 => '~',
            127 => "\x7f",
            128 => "\x80",
            129 => "\x81",
            130 => "\x82",
            131 => "\x83",
            132 => "\x84",
            133 => "\x85",
            134 => "\x86",
            135 => "\x87",
            136 => "\x88",
            137 => "\x89",
            138 => "\x8a",
            139 => "\x8b",
            140 => "\x8c",
            141 => "\x8d",
            142 => "\x8e",
            143 => "\x8f",
            144 => "\x90",
            145 => "\x91",
            146 => "\x92",
            147 => "\x93",
            148 => "\x94",
            149 => "\x95",
            150 => "\x96",
            151 => "\x97",
            152 => "\x98",
            153 => "\x99",
            154 => "\x9a",
            155 => "\x9b",
            156 => "\x9c",
            157 => "\x9d",
            158 => "\x9e",
            159 => "\x9f",
            160 => "\xa0",
            161 => "\xa1",
            162 => "\xa2",
            163 => "\xa3",
            164 => "\xa4",
            165 => "\xa5",
            166 => "\xa6",
            167 => "\xa7",
            168 => "\xa8",
            169 => "\xa9",
            170 => "\xaa",
            171 => "\xab",
            172 => "\xac",
            173 => "\xad",
            174 => "\xae",
            175 => "\xaf",
            176 => "\xb0",
            177 => "\xb1",
            178 => "\xb2",
            179 => "\xb3",
            180 => "\xb4",
            181 => "\xb5",
            182 => "\xb6",
            183 => "\xb7",
            184 => "\xb8",
            185 => "\xb9",
            186 => "\xba",
            187 => "\xbb",
            188 => "\xbc",
            189 => "\xbd",
            190 => "\xbe",
            191 => "\xbf",
            192 => "\xc0",
            193 => "\xc1",
            194 => "\xc2",
            195 => "\xc3",
            196 => "\xc4",
            197 => "\xc5",
            198 => "\xc6",
            199 => "\xc7",
            200 => "\xc8",
            201 => "\xc9",
            202 => "\xca",
            203 => "\xcb",
            204 => "\xcc",
            205 => "\xcd",
            206 => "\xce",
            207 => "\xcf",
            208 => "\xd0",
            209 => "\xd1",
            210 => "\xd2",
            211 => "\xd3",
            212 => "\xd4",
            213 => "\xd5",
            214 => "\xd6",
            215 => "\xd7",
            216 => "\xd8",
            217 => "\xd9",
            218 => "\xda",
            219 => "\xdb",
            220 => "\xdc",
            221 => "\xdd",
            222 => "\xde",
            223 => "\xdf",
            224 => "\xe0",
            225 => "\xe1",
            226 => "\xe2",
            227 => "\xe3",
            228 => "\xe4",
            229 => "\xe5",
            230 => "\xe6",
            231 => "\xe7",
            232 => "\xe8",
            233 => "\xe9",
            234 => "\xea",
            235 => "\xeb",
            236 => "\xec",
            237 => "\xed",
            238 => "\xee",
            239 => "\xef",
            240 => "\xf0",
            241 => "\xf1",
            242 => "\xf2",
            243 => "\xf3",
            244 => "\xf4",
            245 => "\xf5",
            246 => "\xf6",
            247 => "\xf7",
            248 => "\xf8",
            249 => "\xf9",
            250 => "\xfa",
            251 => "\xfb",
            252 => "\xfc",
            253 => "\xfd",
            254 => "\xfe",
            255 => "\xff",

            default => "\0",
        };
    }
}
