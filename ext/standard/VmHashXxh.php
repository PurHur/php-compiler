<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * xxh3 / xxh128 digests — pure PHP via {@see VmHashXxhPure} (#5165, #12209).
 *
 * php-src: ext/hash/hash_xxhash.c
 */
final class VmHashXxh
{
    public static function available(): bool
    {
        return VmHashXxhPure::available();
    }

    /** @return list<int> 8-byte big-endian digest */
    public static function xxh3DigestBytes(string $data): ?array
    {
        return VmHashXxhPure::xxh3DigestBytes($data);
    }

    /** @return list<int> 16-byte canonical digest (high64 || low64, big-endian) */
    public static function xxh128DigestBytes(string $data): ?array
    {
        return VmHashXxhPure::xxh128DigestBytes($data);
    }
}
