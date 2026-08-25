<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash() / hash_hmac() / hash_pbkdf2() / hash_hkdf() for compiled JIT/AOT modules (#9164, #21026).
 *
 * NestedJIT leaf: {@see \phpc_hash_crypto_hash} / hmac / pbkdf2 / hkdf → {@see \PHPCompiler\ext\hash\JitHashCryptoKernel}
 * EVP (HashAlgos #20652 shape). Avoids NestedJIT of VmHashNative (#16075 / #21026).
 *
 * Non-crypto algos (crc32 family, adler32, fnv) are not in OpenSSL EVP — NestedJIT-safe
 * digests run before the EVP leaf (#34829). Prefer {@see intdiv} / multiply over `>>` / `<<`
 * (peer {@see Crc32JitHelper} / {@see Base64JitHelper}).
 * php-src: ext/hash/hash.c, hash_crc32.c, hash_adler32.c, hash_fnv.c
 */
final class HashCryptoJitHelper
{
    private const UINT32_MASK = 4294967295;

    private const FNV1_32_OFFSET = 2166136261;

    private const FNV1_32_PRIME = 16777619;

    private const ADLER_BASE = 65521;

    /** IEEE CRC32B reflected polynomial (crc32.c / hash crc32b). */
    private const POLY_CRC32B = 3988292384; // 0xEDB88320

    /** Castagnoli CRC32C reflected polynomial (hash_crc32.c). */
    private const POLY_CRC32C = 2197175160; // 0x82F63B78

    /** Non-reflected CRC32 polynomial (hash_crc32.c PHP_HASH_CRC32). */
    private const POLY_CRC32 = 79764919; // 0x04C11DB7

    public static function hash(string $algo, string $data, bool $raw): string
    {
        $nonCrypto = self::tryNonCryptoHash($algo, $data, $raw);
        if (null !== $nonCrypto) {
            return $nonCrypto;
        }

        return \phpc_hash_crypto_hash($algo, $data, $raw);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw): string
    {
        return \phpc_hash_crypto_hmac($algo, $data, $key, $raw);
    }

    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length,
        bool $raw
    ): string {
        return \phpc_hash_crypto_pbkdf2($algo, $password, $salt, $iterations, $length, $raw);
    }

    public static function hashHkdf(
        string $algo,
        string $key,
        int $length,
        string $info,
        string $salt
    ): string {
        return \phpc_hash_crypto_hkdf($algo, $key, $length, $info, $salt);
    }

    /** @return string|null */
    private static function tryNonCryptoHash(string $algo, string $data, bool $raw): ?string
    {
        if (self::eqCi($algo, 'crc32b')) {
            return self::formatU32(self::crc32b($data), $raw);
        }
        if (self::eqCi($algo, 'crc32c')) {
            return self::formatU32(self::crc32c($data), $raw);
        }
        if (self::eqCi($algo, 'crc32')) {
            return self::formatU32(self::swapEndian32(self::hashCrc32($data)), $raw);
        }
        if (self::eqCi($algo, 'adler32')) {
            return self::formatU32(self::adler32($data), $raw);
        }
        if (self::eqCi($algo, 'fnv132')) {
            return self::formatU32(self::fnv132($data), $raw);
        }
        if (self::eqCi($algo, 'fnv1a32')) {
            return self::formatU32(self::fnv1a32($data), $raw);
        }

        return null;
    }

    private static function crc32b(string $data): int
    {
        $state = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $state = self::crcUpdateReflected($state, $byte, self::POLY_CRC32B);
        }

        return self::u32($state ^ self::UINT32_MASK);
    }

    private static function crc32c(string $data): int
    {
        $state = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $state = self::crcUpdateReflected($state, $byte, self::POLY_CRC32C);
        }

        return self::u32($state ^ self::UINT32_MASK);
    }

    private static function crcUpdateReflected(int $state, int $byte, int $poly): int
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

    private static function hashCrc32(string $data): int
    {
        $crc = self::UINT32_MASK;
        $len = self::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $byte = self::byteOrd($data[$i]);
            $crc = self::u32($crc ^ ($byte * 16777216));
            for ($j = 0; $j < 8; ++$j) {
                $high = ($crc & 2147483648) !== 0;
                $crc = self::u32($crc * 2);
                if ($high) {
                    $crc = self::u32($crc ^ self::POLY_CRC32);
                }
            }
        }

        return self::u32($crc ^ self::UINT32_MASK);
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

        return self::u32(($b * 65536) + $a);
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

    private static function swapEndian32(int $v): int
    {
        $v = self::u32($v);
        $b0 = $v % 256;
        $b1 = \intdiv($v, 256) % 256;
        $b2 = \intdiv($v, 65536) % 256;
        $b3 = \intdiv($v, 16777216) % 256;

        return self::u32(($b0 * 16777216) + ($b1 * 65536) + ($b2 * 256) + $b3);
    }

    private static function formatU32(int $u, bool $raw): string
    {
        $u = self::u32($u);
        $b0 = \intdiv($u, 16777216) % 256;
        $b1 = \intdiv($u, 65536) % 256;
        $b2 = \intdiv($u, 256) % 256;
        $b3 = $u % 256;
        if ($raw) {
            return self::byteAt($b0).self::byteAt($b1).self::byteAt($b2).self::byteAt($b3);
        }

        return self::hexByte($b0).self::hexByte($b1).self::hexByte($b2).self::hexByte($b3);
    }

    private static function hexByte(int $byte): string
    {
        static $hex = '0123456789abcdef';
        $hi = \intdiv($byte, 16) % 16;
        $lo = $byte % 16;

        return $hex[$hi].$hex[$lo];
    }

    private static function u32(int $value): int
    {
        return $value & self::UINT32_MASK;
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
                $ca += 32;
            }
            if ($cb >= 65 && $cb <= 90) {
                $cb += 32;
            }
            if ($ca !== $cb) {
                return false;
            }
        }

        return true;
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
        // Same NestedJIT-safe table as Crc32JitHelper (#20452).
        return match ($code) {
            0 => "\0", 1 => "\x01", 2 => "\x02", 3 => "\x03", 4 => "\x04", 5 => "\x05",
            6 => "\x06", 7 => "\x07", 8 => "\x08", 9 => "\x09", 10 => "\x0a", 11 => "\x0b",
            12 => "\x0c", 13 => "\x0d", 14 => "\x0e", 15 => "\x0f", 16 => "\x10", 17 => "\x11",
            18 => "\x12", 19 => "\x13", 20 => "\x14", 21 => "\x15", 22 => "\x16", 23 => "\x17",
            24 => "\x18", 25 => "\x19", 26 => "\x1a", 27 => "\x1b", 28 => "\x1c", 29 => "\x1d",
            30 => "\x1e", 31 => "\x1f", 32 => ' ', 33 => '!', 34 => '"', 35 => '#', 36 => '$',
            37 => '%', 38 => '&', 39 => "'", 40 => '(', 41 => ')', 42 => '*', 43 => '+',
            44 => ',', 45 => '-', 46 => '.', 47 => '/', 48 => '0', 49 => '1', 50 => '2',
            51 => '3', 52 => '4', 53 => '5', 54 => '6', 55 => '7', 56 => '8', 57 => '9',
            58 => ':', 59 => ';', 60 => '<', 61 => '=', 62 => '>', 63 => '?', 64 => '@',
            65 => 'A', 66 => 'B', 67 => 'C', 68 => 'D', 69 => 'E', 70 => 'F', 71 => 'G',
            72 => 'H', 73 => 'I', 74 => 'J', 75 => 'K', 76 => 'L', 77 => 'M', 78 => 'N',
            79 => 'O', 80 => 'P', 81 => 'Q', 82 => 'R', 83 => 'S', 84 => 'T', 85 => 'U',
            86 => 'V', 87 => 'W', 88 => 'X', 89 => 'Y', 90 => 'Z', 91 => '[', 92 => '\\',
            93 => ']', 94 => '^', 95 => '_', 96 => '`', 97 => 'a', 98 => 'b', 99 => 'c',
            100 => 'd', 101 => 'e', 102 => 'f', 103 => 'g', 104 => 'h', 105 => 'i', 106 => 'j',
            107 => 'k', 108 => 'l', 109 => 'm', 110 => 'n', 111 => 'o', 112 => 'p', 113 => 'q',
            114 => 'r', 115 => 's', 116 => 't', 117 => 'u', 118 => 'v', 119 => 'w', 120 => 'x',
            121 => 'y', 122 => 'z', 123 => '{', 124 => '|', 125 => '}', 126 => '~', 127 => "\x7f",
            128 => "\x80", 129 => "\x81", 130 => "\x82", 131 => "\x83", 132 => "\x84", 133 => "\x85",
            134 => "\x86", 135 => "\x87", 136 => "\x88", 137 => "\x89", 138 => "\x8a", 139 => "\x8b",
            140 => "\x8c", 141 => "\x8d", 142 => "\x8e", 143 => "\x8f", 144 => "\x90", 145 => "\x91",
            146 => "\x92", 147 => "\x93", 148 => "\x94", 149 => "\x95", 150 => "\x96", 151 => "\x97",
            152 => "\x98", 153 => "\x99", 154 => "\x9a", 155 => "\x9b", 156 => "\x9c", 157 => "\x9d",
            158 => "\x9e", 159 => "\x9f", 160 => "\xa0", 161 => "\xa1", 162 => "\xa2", 163 => "\xa3",
            164 => "\xa4", 165 => "\xa5", 166 => "\xa6", 167 => "\xa7", 168 => "\xa8", 169 => "\xa9",
            170 => "\xaa", 171 => "\xab", 172 => "\xac", 173 => "\xad", 174 => "\xae", 175 => "\xaf",
            176 => "\xb0", 177 => "\xb1", 178 => "\xb2", 179 => "\xb3", 180 => "\xb4", 181 => "\xb5",
            182 => "\xb6", 183 => "\xb7", 184 => "\xb8", 185 => "\xb9", 186 => "\xba", 187 => "\xbb",
            188 => "\xbc", 189 => "\xbd", 190 => "\xbe", 191 => "\xbf", 192 => "\xc0", 193 => "\xc1",
            194 => "\xc2", 195 => "\xc3", 196 => "\xc4", 197 => "\xc5", 198 => "\xc6", 199 => "\xc7",
            200 => "\xc8", 201 => "\xc9", 202 => "\xca", 203 => "\xcb", 204 => "\xcc", 205 => "\xcd",
            206 => "\xce", 207 => "\xcf", 208 => "\xd0", 209 => "\xd1", 210 => "\xd2", 211 => "\xd3",
            212 => "\xd4", 213 => "\xd5", 214 => "\xd6", 215 => "\xd7", 216 => "\xd8", 217 => "\xd9",
            218 => "\xda", 219 => "\xdb", 220 => "\xdc", 221 => "\xdd", 222 => "\xde", 223 => "\xdf",
            224 => "\xe0", 225 => "\xe1", 226 => "\xe2", 227 => "\xe3", 228 => "\xe4", 229 => "\xe5",
            230 => "\xe6", 231 => "\xe7", 232 => "\xe8", 233 => "\xe9", 234 => "\xea", 235 => "\xeb",
            236 => "\xec", 237 => "\xed", 238 => "\xee", 239 => "\xef", 240 => "\xf0", 241 => "\xf1",
            242 => "\xf2", 243 => "\xf3", 244 => "\xf4", 245 => "\xf5", 246 => "\xf6", 247 => "\xf7",
            248 => "\xf8", 249 => "\xf9", 250 => "\xfa", 251 => "\xfb", 252 => "\xfc", 253 => "\xfd",
            254 => "\xfe", 255 => "\xff",
            default => "\0",
        };
    }
}
