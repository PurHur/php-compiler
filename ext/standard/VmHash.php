<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM hash() / hash_hmac() — native digests via VmHashNative (issue #4790; JIT via StringHashCryptoJit).
 */
final class VmHash
{
    /** Digest algorithms supported by VmHashNative / StringHashCryptoJit (issue #6229, #6937, #4644, #5165). */
    private const HASH_ALGOS = [
        'md5', 'sha1', 'sha256',
        'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512',
        'crc32', 'crc32b', 'crc32c', 'adler32', 'fnv132', 'fnv1a32',
        'xxh3', 'xxh128',
    ];

    public const HASH_UNKNOWN_ALGO_MSG = 'hash(): Argument #1 ($algo) must be a valid hashing algorithm';

    public static function hash(string $algo, string $data, bool $raw = false): string
    {
        self::ensureDigestAlgo($algo);
        $lower = \strtolower($algo);
        if (VmHashHostFallback::supportsDigest($lower)) {
            $digest = VmHashHostFallback::hash($lower, $data, $raw);
            if (false === $digest) {
                throw new \ValueError(self::HASH_UNKNOWN_ALGO_MSG);
            }

            return $digest;
        }

        $native = VmHashNative::hash($algo, $data, $raw);
        if (false === $native) {
            throw new \ValueError(self::HASH_UNKNOWN_ALGO_MSG);
        }

        return $native;
    }

    /** @throws \ValueError ext/hash/hash.c unknown algo (issue #4186). */
    public static function ensureDigestAlgo(string $algo): void
    {
        $lower = \strtolower($algo);
        foreach (self::HASH_ALGOS as $known) {
            if ($lower === $known) {
                return;
            }
        }
        if (VmHashHostFallback::supportsDigest($lower)) {
            return;
        }

        throw new \ValueError(self::HASH_UNKNOWN_ALGO_MSG);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw = false): string|false
    {
        $lower = \strtolower($algo);
        if (VmHashHostFallback::supportsHmac($lower)) {
            return VmHashHostFallback::hashHmac($lower, $data, $key, $raw);
        }

        return VmHashNative::hashHmac($algo, $data, $key, $raw);
    }

    /**
     * hash_hkdf() — RFC 5869 HKDF via VmHashNative (issue #5025, ext/hash/hash_hkdf.c parity).
     */
    public static function hashHkdf(
        string $algo,
        string $key,
        int $length = 0,
        string $info = '',
        string $salt = ''
    ): string {
        if ('' === $key) {
            throw new \ValueError('hash_hkdf(): Argument #2 ($key) cannot be empty');
        }

        return VmHashNative::hashHkdf($algo, $key, $length, $info, $salt);
    }

    /**
     * hash_pbkdf2() — native PBKDF2 via VmHashNative (issue #6186, ext/hash/hash_pbkdf2.c parity).
     */
    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length = 0,
        bool $raw = false
    ): string {
        if ($iterations < 1) {
            throw new \ValueError('hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0');
        }
        if ($length < 0) {
            throw new \ValueError('hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0');
        }

        return VmHashNative::hashPbkdf2($algo, $password, $salt, $iterations, $length, $raw);
    }

    /** hash_algos() — digest names registered in ext/hash (ext/hash/hash.c, issues #6937, #11463). */
    public static function algos(): HashTable
    {
        $ht = new HashTable();
        foreach (HashAlgosRegistry::ALL_ALGOS as $algo) {
            $var = new Variable();
            $var->string($algo);
            $ht->append($var);
        }

        return $ht;
    }

    /** hash_hmac_algos() — HMAC-capable digest names (ext/hash/hash.c, issues #6229, #6365). */
    public static function hmacAlgos(): HashTable
    {
        $ht = new HashTable();
        foreach (HashAlgosRegistry::HMAC_ALGOS as $algo) {
            $var = new Variable();
            $var->string($algo);
            $ht->append($var);
        }

        return $ht;
    }

    /** Timing-safe string compare for hash_equals() (issue #2179). */
    public static function equals(string $known, string $user): bool
    {
        if (\strlen($known) !== \strlen($user)) {
            return false;
        }
        $result = 0;
        $len = \strlen($known);
        for ($i = 0; $i < $len; $i++) {
            $result |= \ord($known[$i]) ^ \ord($user[$i]);
        }

        return 0 === $result;
    }
}
