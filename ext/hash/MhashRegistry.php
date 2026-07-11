<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

/**
 * MHASH_* algorithm registry (php-src ext/hash/hash.c mhash_to_hash; #14975).
 *
 * Maps legacy mhash integer IDs to ext/hash digest names and Zend-exposed metadata.
 */
final class MhashRegistry
{
    /**
     * @var array<int, array{name: string, hash_algo: string, block_size: int, digest_size: int}>
     */
    public const ALGOS = [
        0 => ['name' => 'CRC32', 'hash_algo' => 'crc32', 'block_size' => 4, 'digest_size' => 4],
        1 => ['name' => 'MD5', 'hash_algo' => 'md5', 'block_size' => 16, 'digest_size' => 16],
        2 => ['name' => 'SHA1', 'hash_algo' => 'sha1', 'block_size' => 20, 'digest_size' => 20],
        3 => ['name' => 'HAVAL256', 'hash_algo' => 'haval256,3', 'block_size' => 32, 'digest_size' => 32],
        5 => ['name' => 'RIPEMD160', 'hash_algo' => 'ripemd160', 'block_size' => 20, 'digest_size' => 20],
        7 => ['name' => 'TIGER', 'hash_algo' => 'tiger192,3', 'block_size' => 24, 'digest_size' => 24],
        8 => ['name' => 'GOST', 'hash_algo' => 'gost', 'block_size' => 32, 'digest_size' => 32],
        9 => ['name' => 'CRC32B', 'hash_algo' => 'crc32b', 'block_size' => 4, 'digest_size' => 4],
        10 => ['name' => 'HAVAL224', 'hash_algo' => 'haval224,3', 'block_size' => 28, 'digest_size' => 28],
        11 => ['name' => 'HAVAL192', 'hash_algo' => 'haval192,3', 'block_size' => 24, 'digest_size' => 24],
        12 => ['name' => 'HAVAL160', 'hash_algo' => 'haval160,3', 'block_size' => 20, 'digest_size' => 20],
        13 => ['name' => 'HAVAL128', 'hash_algo' => 'haval128,3', 'block_size' => 16, 'digest_size' => 16],
        14 => ['name' => 'TIGER128', 'hash_algo' => 'tiger128,3', 'block_size' => 16, 'digest_size' => 16],
        15 => ['name' => 'TIGER160', 'hash_algo' => 'tiger160,3', 'block_size' => 20, 'digest_size' => 20],
        16 => ['name' => 'MD4', 'hash_algo' => 'md4', 'block_size' => 16, 'digest_size' => 16],
        17 => ['name' => 'SHA256', 'hash_algo' => 'sha256', 'block_size' => 32, 'digest_size' => 32],
        18 => ['name' => 'ADLER32', 'hash_algo' => 'adler32', 'block_size' => 4, 'digest_size' => 4],
        19 => ['name' => 'SHA224', 'hash_algo' => 'sha224', 'block_size' => 28, 'digest_size' => 28],
        20 => ['name' => 'SHA512', 'hash_algo' => 'sha512', 'block_size' => 64, 'digest_size' => 64],
        21 => ['name' => 'SHA384', 'hash_algo' => 'sha384', 'block_size' => 48, 'digest_size' => 48],
        22 => ['name' => 'WHIRLPOOL', 'hash_algo' => 'whirlpool', 'block_size' => 64, 'digest_size' => 64],
        23 => ['name' => 'RIPEMD128', 'hash_algo' => 'ripemd128', 'block_size' => 16, 'digest_size' => 16],
        24 => ['name' => 'RIPEMD256', 'hash_algo' => 'ripemd256', 'block_size' => 32, 'digest_size' => 32],
        25 => ['name' => 'RIPEMD320', 'hash_algo' => 'ripemd320', 'block_size' => 40, 'digest_size' => 40],
        27 => ['name' => 'SNEFRU256', 'hash_algo' => 'snefru', 'block_size' => 32, 'digest_size' => 32],
        28 => ['name' => 'MD2', 'hash_algo' => 'md2', 'block_size' => 16, 'digest_size' => 16],
        29 => ['name' => 'FNV132', 'hash_algo' => 'fnv132', 'block_size' => 4, 'digest_size' => 4],
        30 => ['name' => 'FNV1A32', 'hash_algo' => 'fnv1a32', 'block_size' => 4, 'digest_size' => 4],
        31 => ['name' => 'FNV164', 'hash_algo' => 'fnv164', 'block_size' => 8, 'digest_size' => 8],
        32 => ['name' => 'FNV1A64', 'hash_algo' => 'fnv1a64', 'block_size' => 8, 'digest_size' => 8],
        33 => ['name' => 'JOAAT', 'hash_algo' => 'joaat', 'block_size' => 4, 'digest_size' => 4],
        34 => ['name' => 'CRC32C', 'hash_algo' => 'crc32c', 'block_size' => 4, 'digest_size' => 4],
        35 => ['name' => 'MURMUR3A', 'hash_algo' => 'murmur3a', 'block_size' => 4, 'digest_size' => 4],
        36 => ['name' => 'MURMUR3C', 'hash_algo' => 'murmur3c', 'block_size' => 16, 'digest_size' => 16],
        37 => ['name' => 'MURMUR3F', 'hash_algo' => 'murmur3f', 'block_size' => 16, 'digest_size' => 16],
        38 => ['name' => 'XXH32', 'hash_algo' => 'xxh32', 'block_size' => 4, 'digest_size' => 4],
        39 => ['name' => 'XXH64', 'hash_algo' => 'xxh64', 'block_size' => 8, 'digest_size' => 8],
        40 => ['name' => 'XXH3', 'hash_algo' => 'xxh3', 'block_size' => 8, 'digest_size' => 8],
        41 => ['name' => 'XXH128', 'hash_algo' => 'xxh128', 'block_size' => 16, 'digest_size' => 16],
    ];

    /** @return array<string, int> */
    public static function constants(): array
    {
        $out = [];
        foreach (self::ALGOS as $id => $meta) {
            $out['MHASH_'.$meta['name']] = $id;
        }

        return $out;
    }

    public static function count(): int
    {
        // php-src ext/hash/hash.c — mhash_count() returns MHASH_NUM_ALGOS - 1 (42 - 1).
        return 41;
    }

    /** @return array{name: string, hash_algo: string, block_size: int, digest_size: int}|null */
    public static function lookup(int $algorithm): ?array
    {
        return self::ALGOS[$algorithm] ?? null;
    }
}
