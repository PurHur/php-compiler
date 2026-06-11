<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Non-crypto hash() digests — crc32/crc32b/adler32/fnv* (ext/hash; issue #4644).
 *
 * php-src: ext/hash/hash_crc32.c, hash_adler32.c, hash_fnv.c
 */
final class VmHashNonCrypto
{
    private const FNV1_32_OFFSET = 2166136261;
    private const FNV1_32_PRIME = 16777619;
    private const ADLER_BASE = 65521;

    /** CRC32B (IEEE) — same polynomial as crc32() via {@see VmCrc32}. */
    public static function crc32b(string $data): int
    {
        return VmCrc32::compute($data, 0);
    }

    /** CRC32 (Castagnoli-style init/update from php-src PHP_HASH_CRC32). */
    public static function crc32(string $data): int
    {
        $crc = 0xFFFFFFFF;
        $len = VmString::byteLength($data);
        $table = self::crc32Table();
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($data[$i]);
            $crc = ($crc << 8) ^ $table[(($crc >> 24) ^ $byte) & 0xFF];
            $crc &= 0xFFFFFFFF;
        }

        return (int) (~$crc & 0xFFFFFFFF);
    }

    public static function adler32(string $data): int
    {
        $a = 1;
        $b = 0;
        $len = VmString::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $a = ($a + \ord($data[$i])) % self::ADLER_BASE;
            $b = ($b + $a) % self::ADLER_BASE;
        }

        return (($b << 16) | $a) & 0xFFFFFFFF;
    }

    public static function fnv132(string $data): int
    {
        $hash = self::FNV1_32_OFFSET;
        $len = VmString::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $hash = self::u32($hash * self::FNV1_32_PRIME);
            $hash = self::u32($hash ^ \ord($data[$i]));
        }

        return $hash;
    }

    public static function fnv1a32(string $data): int
    {
        $hash = self::FNV1_32_OFFSET;
        $len = VmString::byteLength($data);
        for ($i = 0; $i < $len; ++$i) {
            $hash = self::u32($hash ^ \ord($data[$i]));
            $hash = self::u32($hash * self::FNV1_32_PRIME);
        }

        return $hash;
    }

    /** @return list<int> */
    public static function digestBytes(int $digestU32): array
    {
        $digestU32 &= 0xFFFFFFFF;

        return [
            ($digestU32 >> 24) & 0xFF,
            ($digestU32 >> 16) & 0xFF,
            ($digestU32 >> 8) & 0xFF,
            $digestU32 & 0xFF,
        ];
    }

    /** @var list<int>|null */
    private static ?array $crc32Table = null;

    /** @return list<int> */
    private static function crc32Table(): array
    {
        if (null !== self::$crc32Table) {
            return self::$crc32Table;
        }
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $c = $i << 24;
            for ($j = 0; $j < 8; ++$j) {
                $c = (0 !== ($c & 0x80000000)) ? ((0x04C11DB7 ^ ($c << 1)) & 0xFFFFFFFF) : (($c << 1) & 0xFFFFFFFF);
            }
            $table[$i] = $c;
        }
        self::$crc32Table = $table;

        return $table;
    }

    private static function u32(int $x): int
    {
        return $x & 0xFFFFFFFF;
    }
}
