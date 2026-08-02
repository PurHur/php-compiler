<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quoted_printable_encode/decode for compiled JIT/AOT modules (#9910, #26899, php-in-PHP).
 *
 * Self-contained (no VmString). Encode uses skipNext for CRLF pairs; decode uses a
 * mode state machine (always `$i + 1`) — NestedJIT rejects `$i += 2` / skip-counter
 * decode paths with empty results (#26899).
 *
 * php-src: ext/standard/quot_print.c
 */
final class QuotPrintJitHelper
{
    private const QPRINT_MAXL = 75;

    public static function encode(string $str): string
    {
        $length = 0;
        while (isset($str[$length])) {
            ++$length;
        }
        if (0 === $length) {
            return '';
        }
        $hex = '0123456789ABCDEF';
        $out = '';
        $lp = 0;
        $i = 0;
        $skipNext = 0;
        while ($i < $length) {
            if (1 === $skipNext) {
                $skipNext = 0;
            } else {
                $c = self::byteOrd($str[$i]);
                $isCrlf = 0;
                if (13 === $c && $i + 1 < $length && 10 === self::byteOrd($str[$i + 1])) {
                    $isCrlf = 1;
                }
                if (1 === $isCrlf) {
                    $out .= "\r\n";
                    $lp = 0;
                    $skipNext = 1;
                } else {
                    $nextIsCr = 0;
                    if ($i + 1 < $length && 13 === self::byteOrd($str[$i + 1])) {
                        $nextIsCr = 1;
                    }
                    $mustEncode = 0;
                    if ($c < 32 || 127 === $c || 0 !== ($c & 0x80) || 61 === $c) {
                        $mustEncode = 1;
                    }
                    if (32 === $c && 1 === $nextIsCr) {
                        $mustEncode = 1;
                    }
                    if (1 === $mustEncode) {
                        $lp = $lp + 3;
                        $soft = 0;
                        if ($lp > self::QPRINT_MAXL && $c <= 0x7f) {
                            $soft = 1;
                        }
                        if ($c > 0x7f && $c <= 0xdf && ($lp + 3) > self::QPRINT_MAXL) {
                            $soft = 1;
                        }
                        if ($c > 0xdf && $c <= 0xef && ($lp + 6) > self::QPRINT_MAXL) {
                            $soft = 1;
                        }
                        if ($c > 0xef && $c <= 0xf4 && ($lp + 9) > self::QPRINT_MAXL) {
                            $soft = 1;
                        }
                        if (1 === $soft) {
                            $out .= "=\r\n";
                            $lp = 3;
                        }
                        $out .= '=';
                        $out .= $hex[$c >> 4];
                        $out .= $hex[$c & 0xf];
                    } else {
                        $lp = $lp + 1;
                        if ($lp > self::QPRINT_MAXL) {
                            $out .= "=\r\n";
                            $lp = 1;
                        }
                        $out .= $str[$i];
                    }
                }
            }
            $i = $i + 1;
        }

        return $out;
    }

    public static function decode(string $str): string
    {
        $inLen = 0;
        while (isset($str[$inLen])) {
            ++$inLen;
        }
        if (0 === $inLen) {
            return '';
        }
        $out = '';
        $i = 0;
        // mode: 0 normal, 1 seen '=', 2 seen '=H', 3 skipping soft WS after '='
        $mode = 0;
        $hex1 = 0;
        while ($i < $inLen) {
            $ch = $str[$i];
            $c = self::byteOrd($ch);
            if (0 === $mode) {
                if (61 === $c) {
                    $mode = 1;
                } else {
                    $out .= $ch;
                }
            } else {
                if (1 === $mode) {
                    $h = self::hexValOrNeg($c);
                    if ($h >= 0) {
                        $hex1 = $h;
                        $mode = 2;
                    } else {
                        if (32 === $c || 9 === $c) {
                            $mode = 3;
                        } else {
                            if (13 === $c) {
                                $mode = 4;
                            } else {
                                if (10 === $c) {
                                    $mode = 0;
                                } else {
                                    $out .= '=';
                                    $out .= $ch;
                                    $mode = 0;
                                }
                            }
                        }
                    }
                } else {
                    if (2 === $mode) {
                        $h = self::hexValOrNeg($c);
                        if ($h >= 0) {
                            $code = ($hex1 << 4) + $h;
                            $out .= self::byteAt($code);
                            $mode = 0;
                        } else {
                            $out .= '=';
                            $out .= self::byteAt(self::hexNibbleChar($hex1));
                            // reprocess current char in mode 0 next — NestedJIT: emit and handle now
                            if (61 === $c) {
                                $mode = 1;
                            } else {
                                $out .= $ch;
                                $mode = 0;
                            }
                        }
                    } else {
                        if (3 === $mode) {
                            // mode 3: soft whitespace after '='
                            if (32 === $c || 9 === $c) {
                                // stay in mode 3
                            } else {
                                if (13 === $c) {
                                    $mode = 4;
                                } else {
                                    if (10 === $c) {
                                        $mode = 0;
                                    } else {
                                        $out .= '=';
                                        if (61 === $c) {
                                            $mode = 1;
                                        } else {
                                            $out .= $ch;
                                            $mode = 0;
                                        }
                                    }
                                }
                            }
                        } else {
                            // mode 4: skip LF after soft-break CR
                            if (10 === $c) {
                                $mode = 0;
                            } else {
                                if (61 === $c) {
                                    $mode = 1;
                                } else {
                                    $out .= $ch;
                                    $mode = 0;
                                }
                            }
                        }
                    }
                }
            }
            $i = $i + 1;
        }
        if (1 === $mode) {
            $out .= '=';
        }
        if (2 === $mode) {
            $out .= '=';
            $out .= self::byteAt(self::hexNibbleChar($hex1));
        }

        return $out;
    }

    /** @return int hex nibble or -1 */
    private static function hexValOrNeg(int $c): int
    {
        $v = -1;
        if ($c >= 48 && $c <= 57) {
            $v = $c - 48;
        }
        if ($c >= 65 && $c <= 70) {
            $v = $c - 65 + 10;
        }
        if ($c >= 97 && $c <= 102) {
            $v = $c - 97 + 10;
        }

        return $v;
    }

    private static function hexNibbleChar(int $n): int
    {
        $c = 48 + $n;
        if ($n >= 10) {
            $c = 65 + ($n - 10);
        }

        return $c;
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
            34 => '"',
            35 => '#',
            36 => '$',
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
            92 => '\\',
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
