<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM hash() / hash_hmac() — native digests via VmHashNative (issue #4790, lib/AOT/runtime/hash_crypto.c).
 */
final class VmHash
{
    /** HMAC-capable algorithms supported by VmHashNative / hash_crypto.c (issue #6229). */
    private const HMAC_ALGOS = ['md5', 'sha1', 'sha256'];
    public static function hash(string $algo, string $data, bool $raw = false): string|false
    {
        return VmHashNative::hash($algo, $data, $raw);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw = false): string|false
    {
        return VmHashNative::hashHmac($algo, $data, $key, $raw);
    }

    /**
     * hash_pbkdf2() — delegates to host PHP (issue #3773, ext/hash/hash_pbkdf2.c parity).
     *
     * @throws \ValueError unknown algorithm (PHP 8+)
     */
    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length = 0,
        bool $raw = false
    ): string {
        return \hash_pbkdf2($algo, $password, $salt, $iterations, $length, $raw);
    }

    /** hash_hmac_algos() — HMAC-capable digest names (ext/hash/hash.c, issue #6229). */
    public static function hmacAlgos(): HashTable
    {
        $ht = new HashTable();
        foreach (self::HMAC_ALGOS as $algo) {
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
