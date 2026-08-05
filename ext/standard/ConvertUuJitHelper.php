<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * convert_uuencode()/convert_uudecode() for compiled JIT/AOT modules (#13227, #18827, #26898).
 *
 * Self-contained NestedJIT helper (no VmString / no static lastString) — peer Hex2bin #27008.
 * Avoid `45 === $len ? 60 : floor` ternary: NestedJIT wrongly took the 60 arm (#26898 diag ee=61).
 *
 * php-src: ext/standard/uuencode.c
 */
final class ConvertUuJitHelper
{
    private const MSG_INVALID = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';

    public static function encode(string $data): string
    {
        $srcLen = self::byteLength($data);
        if (0 === $srcLen) {
            return "`\n";
        }
        $out = '';
        for ($offset = 0; $offset < $srcLen; $offset += 45) {
            $chunkLen = $srcLen - $offset;
            if ($chunkLen > 45) {
                $chunkLen = 45;
            }
            $out .= self::encodeChunk($data, $offset, $chunkLen);
            $out .= "\n";
        }
        $out .= self::uuEnc(0)."\n";

        return $out;
    }

    private static function encodeChunk(string $data, int $offset, int $chunkLen): string
    {
        $out = self::uuEnc($chunkLen);
        $rel = 0;
        while (($rel + 3) <= $chunkLen) {
            $b0 = self::byteOrd($data[$offset + $rel]);
            $b1 = self::byteOrd($data[$offset + $rel + 1]);
            $b2 = self::byteOrd($data[$offset + $rel + 2]);
            $out .= self::uuEnc($b0 >> 2);
            $out .= self::uuEnc((($b0 << 4) & 48) | (($b1 >> 4) & 15));
            $out .= self::uuEnc((($b1 << 2) & 60) | (($b2 >> 6) & 3));
            $out .= self::uuEnc($b2 & 63);
            $rel += 3;
        }
        if ($rel < $chunkLen) {
            $b0 = self::byteOrd($data[$offset + $rel]);
            $b1 = (($rel + 1) < $chunkLen) ? self::byteOrd($data[$offset + $rel + 1]) : 0;
            $b2 = (($rel + 2) < $chunkLen) ? self::byteOrd($data[$offset + $rel + 2]) : 0;
            $left = $chunkLen - $rel;
            $out .= self::uuEnc($b0 >> 2);
            $out .= self::uuEnc((($b0 << 4) & 48) | (($b1 >> 4) & 15));
            $out .= ($left > 1)
                ? self::uuEnc((($b1 << 2) & 60) | (($b2 >> 6) & 3))
                : self::uuEnc(0);
            $out .= ($left > 2)
                ? self::uuEnc($b2 & 63)
                : self::uuEnc(0);
        }

        return $out;
    }

    /**
     * @return string|false
     */
    public static function decodeArgv(string $data)
    {
        $result = self::decodeImpl($data);
        if (false === $result) {
            TriggerErrorJitHelper::warning(self::MSG_INVALID);

            return false;
        }

        return $result;
    }

    /**
     * @return string|false
     */
    private static function decodeImpl(string $src)
    {
        $srcLen = self::byteLength($src);
        if (0 === $srcLen) {
            return false;
        }
        $totalLen = 0;
        $out = '';
        $cursor = 0;
        while ($cursor < $srcLen) {
            $lineLen = self::uuDec(self::byteOrd($src[$cursor]));
            $payload = $cursor + 1;
            if (0 === $lineLen) {
                break;
            }
            if ($lineLen > $srcLen) {
                return false;
            }
            $totalLen += $lineLen;
            $width = self::encodedWidth($lineLen);
            $ee = $payload + $width;
            if ($ee > $srcLen) {
                return false;
            }
            $pos = $payload;
            while ($pos < $ee) {
                if (($pos + 4) > $srcLen) {
                    return false;
                }
                $o0 = self::uuDec(self::byteOrd($src[$pos]));
                $o1 = self::uuDec(self::byteOrd($src[$pos + 1]));
                $o2 = self::uuDec(self::byteOrd($src[$pos + 2]));
                $o3 = self::uuDec(self::byteOrd($src[$pos + 3]));
                $out .= self::byteAt((($o0 << 2) | ($o1 >> 4)) & 255);
                $out .= self::byteAt((($o1 << 4) | ($o2 >> 2)) & 255);
                $out .= self::byteAt((($o2 << 6) | $o3) & 255);
                $pos += 4;
            }
            if ($lineLen < 45) {
                break;
            }
            $cursor = $ee + 1;
        }
        $written = self::byteLength($out);
        if ($written < $totalLen) {
            $need = $totalLen;
            if ($need > $written) {
                $out .= self::byteAt((self::uuDec(self::byteOrd($src[$pos])) << 2 | self::uuDec(self::byteOrd($src[$pos + 1])) >> 4) & 255);
                if ($need > 1) {
                    $out .= self::byteAt((self::uuDec(self::byteOrd($src[$pos + 1])) << 4 | self::uuDec(self::byteOrd($src[$pos + 2])) >> 2) & 255);
                    if ($need > 2) {
                        $out .= self::byteAt((self::uuDec(self::byteOrd($src[$pos + 2])) << 6 | self::uuDec(self::byteOrd($src[$pos + 3]))) & 255);
                    }
                }
            }
        }
        if (self::byteLength($out) !== $totalLen) {
            return self::byteSlice($out, 0, $totalLen);
        }

        return $out;
    }

    /** php-src floor(len * 1.33); full 45-byte line uses width 60 (no ternary — #26898). */
    private static function encodedWidth(int $lineLen): int
    {
        // Use >= 45 (not ===): NestedJIT has miscompiled strict equality on this path (#26898).
        if ($lineLen >= 45) {
            return 60;
        }

        return intdiv($lineLen * 133, 100);
    }

    private static function uuEnc(int $c): string
    {
        if (0 === $c) {
            return '`';
        }

        return self::byteAt(($c & 63) + 32);
    }

    private static function uuDec(int $c): int
    {
        return ($c - 32) & 63;
    }

    private static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
    }

    private static function byteSlice(string $string, int $start, int $length): string
    {
        $out = '';
        $end = $start + $length;
        for ($j = $start; $j < $end; ++$j) {
            if (!isset($string[$j])) {
                break;
            }
            $out .= $string[$j];
        }

        return $out;
    }

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
