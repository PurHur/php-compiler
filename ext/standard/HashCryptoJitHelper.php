<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash() / hash_hmac() / hash_pbkdf2() / hash_hkdf() for compiled JIT/AOT modules (#9164, #21026, #34828).
 *
 * NestedJIT leaf: {@see \phpc_hash_crypto_hash} / hmac / pbkdf2 / hkdf → {@see \PHPCompiler\ext\hash\JitHashCryptoKernel}
 * EVP (HashAlgos #20652 shape). Avoids NestedJIT of VmHashNative (#16075 / #21026).
 *
 * Non-crypto digests (crc32/crc32b/crc32c/adler32/fnv132/fnv1a32) are unknown to EVP — dispatch
 * via {@see HashNonCryptoJitHelper} first (#34828).
 * php-src: ext/hash/hash.c
 */
final class HashCryptoJitHelper
{
    public static function hash(string $algo, string $data, bool $raw): string
    {
        if (HashNonCryptoJitHelper::supports($algo)) {
            return HashNonCryptoJitHelper::digest($algo, $data, $raw);
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
}
