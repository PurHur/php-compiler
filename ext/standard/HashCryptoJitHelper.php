<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash() / hash_hmac() / hash_pbkdf2() / hash_hkdf() for compiled JIT/AOT modules (#9164, php-in-PHP).
 *
 * SSOT: {@see VmHash}
 * php-src: ext/standard/hash.c, ext/standard/hash_hmac.c
 */
final class HashCryptoJitHelper
{
    public static function hash(string $algo, string $data, bool $raw): string
    {
        return VmHash::hash($algo, $data, $raw);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw): string
    {
        return VmHash::hashHmac($algo, $data, $key, $raw);
    }

    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length,
        bool $raw
    ): string {
        return VmHash::hashPbkdf2($algo, $password, $salt, $iterations, $length, $raw);
    }

    public static function hashHkdf(
        string $algo,
        string $key,
        int $length,
        string $info,
        string $salt
    ): string {
        return VmHash::hashHkdf($algo, $key, $length, $info, $salt);
    }
}
