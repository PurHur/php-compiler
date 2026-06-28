<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * SHA-3 digests — pure PHP via {@see VmHashSha3Pure} (#12903).
 *
 * php-src: ext/hash/hash_sha.c
 */
final class VmHashSha3
{
    /** @return list<int> */
    public static function sha3_224DigestBytes(string $data): array
    {
        return VmHashSha3Pure::sha3_224($data);
    }

    /** @return list<int> */
    public static function sha3_256DigestBytes(string $data): array
    {
        return VmHashSha3Pure::sha3_256($data);
    }

    /** @return list<int> */
    public static function sha3_384DigestBytes(string $data): array
    {
        return VmHashSha3Pure::sha3_384($data);
    }

    /** @return list<int> */
    public static function sha3_512DigestBytes(string $data): array
    {
        return VmHashSha3Pure::sha3_512($data);
    }
}
