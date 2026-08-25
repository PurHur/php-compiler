<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Non-crypto hash() digests for NestedJIT/AOT (#34828, #34834, php-in-PHP).
 *
 * libcrypto EVP_get_digestbyname rejects crc32*, adler32, fnv132/64, joaat, and peers —
 * route here from {@see HashCryptoJitHelper::hash} before the EVP leaf (#21026).
 *
 * NestedJIT-safe: no ord()/strlen()/lookup tables; bind locals before nested calls;
 * prefer intdiv over >> for half-steps (peer #34824 / Base64JitHelper).
 * php-src: ext/hash/hash_crc32.c, hash_adler32.c, hash_fnv.c, hash_joaat.c
 */
final class HashNonCryptoJitHelper
{
    private const UINT32_MASK = 4294967295;
    private const POLY_CRC32B = 3988292384; // 0xEDB88320 IEEE reflected
    private const POLY_CRC32C = 2197175160; // 0x82F63B78 Castagnoli reflected
    private const POLY_CRC32 = 79764919; // 0x04C11DB7 non-reflected (hash crc32)
    private const FNV1_32_OFFSET = 2166136261;
    private const FNV1_32_PRIME = 16777619;
    /** FNV-1 64-bit offset basis 0xcbf29ce484222325 as lo/hi u32. */
    private const FNV1_64_OFFSET_LO = 0x84222325;
    private const FNV1_64_OFFSET_HI = 0xcbf29ce4;
    /** FNV-1 64-bit prime 0x100000001b3 as lo/hi u32. */
    private const FNV1_64_PRIME_LO = 0x1b3;
    private const FNV1_64_PRIME_HI = 0x100;
    private const ADLER_BASE = 65521;

    public static function supports(string $algo): bool
    {
        return self::algoId($algo) !== 0;
    }

    public static function digest(string $algo, string $data, bool $raw): string
    {
        $id = self::algoId($algo);
        if (7 === $id) {
            $bin = self::fnv64Bytes($data, false);
            if ($raw) {
                return $bin;
            }

            return self::binToHex($bin);
        }
        if (8 === $id) {
            $bin = self::fnv64Bytes($data, true);
            if ($raw) {
                return $bin;
            }

            return self::binToHex($bin);
        }
        if (9 === $id) {
            $bin = self::u32ToBytes(self::joaat($data));
            if ($raw) {
                return $bin;
            }

            return self::binToHex($bin);
        }
        // #34828 flat if/elseif — NestedJIT/AOT regresses when nested inside else (#34834).
        $u = 0;
        if (1 === $id) {
            // crc32b — IEEE / same as crc32()
            $u = self::crc32b($data);
        } elseif (2 === $id) {
            // crc32 — non-reflected then swap endian for digest bytes
            $u = self::swapEndian32(self::crc32NonReflected($data));
        } elseif (3 === $id) {
            $u = self::crc32c($data);
        } elseif (4 === $id) {
            $u = self::adler32($data);
        } elseif (5 === $id) {
            $u = self::fnv132($data);
        } elseif (6 === $id) {
            $u = self::fnv1a32($data);
        } else {
            return '';
        }
        $bin = self::u32ToBytes($u);
        if ($raw) {
            return $bin;
        }

        return self::binToHex($bin);
    }

    private static function algoId(string $algo): int
    {
        if (self::eqCi($algo, 'crc32b')) {
            return 1;
        }
        if (self::eqCi($algo, 'crc32')) {
            return 2;
        }
        if (self::eqCi($algo, 'crc32c')) {
            return 3;
        }
        if (self::eqCi($algo, 'adler32')) {
            return 4;
        }
        if (self::eqCi($algo, 'fnv132')) {
            return 5;
        }
        if (self::eqCi($algo, 'fnv1a32')) {
            return 6;
        }
        if (self::eqCi($algo, 'fnv164')) {
            return 7;
        }
        if (self::eqCi($algo, 'fnv1a64')) {
            return 8;
        }
        if (self::eqCi($algo, 'joaat')) {
            return 9;
        }

        return 0;
    }

    private static function crc32b(string $data): int
    {
        $state = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $state = self::updateReflected($state, $byte, self::POLY_CRC32B);
        }

        return self::u32($state ^ self::UINT32_MASK);
    }

    private static function crc32c(string $data): int
    {
        $state = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $state = self::updateReflected($state, $byte, self::POLY_CRC32C);
        }

        return self::u32($state ^ self::UINT32_MASK);
    }

    private static function crc32NonReflected(string $data): int
    {
        $crc = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $crc = self::u32($crc ^ self::u32($byte * 16777216)); // << 24
            for ($j = 0; $j < 8; ++$j) {
                $high = ($crc & 2147483648) !== 0; // 0x80000000
                $crc = self::u32($crc * 2); // << 1
                if ($high) {
                    $crc = self::u32($crc ^ self::POLY_CRC32);
                }
            }
        }

        return self::u32($crc ^ self::UINT32_MASK);
    }

    private static function updateReflected(int $state, int $byte, int $poly): int
    {
        $state = self::u32($state ^ $byte);
        for ($j = 0; $j < 8; ++$j) {
            $odd = ($state & 1) !== 0;
            $state = \intdiv($state, 2);
            if ($odd) {
                $state = self::u32($state ^ $poly);
            }
        }

        return $state;
    }

    private static function adler32(string $data): int
    {
        $a = 1;
        $b = 0;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $a = ($a + $byte) % self::ADLER_BASE;
            $b = ($b + $a) % self::ADLER_BASE;
        }

        return self::u32(($b * 65536) | $a); // << 16
    }

    private static function fnv132(string $data): int
    {
        $hash = self::FNV1_32_OFFSET;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $hash = self::u32($hash * self::FNV1_32_PRIME);
            $hash = self::u32($hash ^ $byte);
        }

        return $hash;
    }

    private static function fnv1a32(string $data): int
    {
        $hash = self::FNV1_32_OFFSET;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $hash = self::u32($hash ^ $byte);
            $hash = self::u32($hash * self::FNV1_32_PRIME);
        }

        return $hash;
    }

    /** php-src hash_fnv.c — FNV-1 / FNV-1a 64-bit; digest is big-endian. */
    private static function fnv64Bytes(string $data, bool $alternate): string
    {
        $lo = self::FNV1_64_OFFSET_LO;
        $hi = self::FNV1_64_OFFSET_HI;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            if ($alternate) {
                $lo = self::u32($lo ^ $byte);
                [$lo, $hi] = self::fnv64MulPrime($lo, $hi);
            } else {
                [$lo, $hi] = self::fnv64MulPrime($lo, $hi);
                $lo = self::u32($lo ^ $byte);
            }
        }

        return self::u64ToBytesBe($lo, $hi);
    }

    /**
     * Multiply (hi<<32|lo) by FNV-1 64-bit prime; keep low 64 bits as [lo,hi].
     *
     * @return array{0:int,1:int}
     */
    private static function fnv64MulPrime(int $lo, int $hi): array
    {
        [$p0l, $p0h] = self::mul32($lo, self::FNV1_64_PRIME_LO);
        [$p1l] = self::mul32($lo, self::FNV1_64_PRIME_HI);
        [$p2l] = self::mul32($hi, self::FNV1_64_PRIME_LO);
        $rLo = $p0l;
        $rHi = self::u32($p0h + $p1l + $p2l);

        return [$rLo, $rHi];
    }

    /**
     * 32x32 -> 64 as [lo32, hi32]. NestedJIT-safe (no pack/unpack).
     *
     * @return array{0:int,1:int}
     */
    private static function mul32(int $x, int $y): array
    {
        $x = self::u32($x);
        $y = self::u32($y);
        $x0 = $x % 65536;
        $x1 = \intdiv($x, 65536);
        $y0 = $y % 65536;
        $y1 = \intdiv($y, 65536);
        $p00 = $x0 * $y0;
        $p01 = $x0 * $y1;
        $p10 = $x1 * $y0;
        $p11 = $x1 * $y1;
        $mid = ($p01 % 65536) + ($p10 % 65536) + \intdiv($p00, 65536);
        $hi = \intdiv($p01, 65536) + \intdiv($p10, 65536) + \intdiv($mid, 65536) + $p11;
        $lo = (($mid % 65536) * 65536) + ($p00 % 65536);

        return [self::u32($lo), self::u32($hi)];
    }

    private static function u64ToBytesBe(int $lo, int $hi): string
    {
        $lo = self::u32($lo);
        $hi = self::u32($hi);

        return self::byteAt(\intdiv($hi, 16777216) % 256)
            .self::byteAt(\intdiv($hi, 65536) % 256)
            .self::byteAt(\intdiv($hi, 256) % 256)
            .self::byteAt($hi % 256)
            .self::byteAt(\intdiv($lo, 16777216) % 256)
            .self::byteAt(\intdiv($lo, 65536) % 256)
            .self::byteAt(\intdiv($lo, 256) % 256)
            .self::byteAt($lo % 256);
    }

    /** php-src hash_joaat.c — Jenkins one-at-a-time + final mix; digest big-endian. */
    private static function joaat(string $data): int
    {
        $h = 0;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $h = self::u32($h + $byte);
            $h = self::u32($h + self::u32($h * 1024)); // << 10
            $h = self::u32($h ^ \intdiv($h, 64)); // >> 6
        }
        $h = self::u32($h + self::u32($h * 8)); // << 3
        $h = self::u32($h ^ \intdiv($h, 2048)); // >> 11
        $h = self::u32($h + self::u32($h * 32768)); // << 15

        return $h;
    }

    private static function swapEndian32(int $v): int
    {
        $v = self::u32($v);
        $b0 = $v % 256;
        $b1 = \intdiv($v, 256) % 256;
        $b2 = \intdiv($v, 65536) % 256;
        $b3 = \intdiv($v, 16777216) % 256;

        return self::u32($b0 * 16777216 + $b1 * 65536 + $b2 * 256 + $b3);
    }

    private static function u32ToBytes(int $u): string
    {
        $u = self::u32($u);
        $b3 = \intdiv($u, 16777216) % 256;
        $b2 = \intdiv($u, 65536) % 256;
        $b1 = \intdiv($u, 256) % 256;
        $b0 = $u % 256;

        return self::byteAt($b3).self::byteAt($b2).self::byteAt($b1).self::byteAt($b0);
    }

    private static function binToHex(string $bin): string
    {
        $hex = '0123456789abcdef';
        $len = self::byteLength($bin);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($bin[$i]);
            $hi = \intdiv($ord, 16);
            $lo = $ord % 16;
            $out .= $hex[$hi];
            $out .= $hex[$lo];
        }

        return $out;
    }

    private static function eqCi(string $a, string $b): bool
    {
        $la = self::byteLength($a);
        $lb = self::byteLength($b);
        if ($la !== $lb) {
            return false;
        }
        for ($i = 0; $i < $la; ++$i) {
            $ca = self::byteOrd($a[$i]);
            $cb = self::byteOrd($b[$i]);
            if ($ca >= 65 && $ca <= 90) {
                $ca = $ca + 32;
            }
            if ($cb >= 65 && $cb <= 90) {
                $cb = $cb + 32;
            }
            if ($ca !== $cb) {
                return false;
            }
        }

        return true;
    }

    private static function u32(int $value): int
    {
        return $value & self::UINT32_MASK;
    }

    private static function byteLength(string $data): int
    {
        $len = 0;
        while (isset($data[$len])) {
            ++$len;
        }

        return $len;
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
