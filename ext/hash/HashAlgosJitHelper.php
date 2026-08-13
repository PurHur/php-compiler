<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

/**
 * hash_algos() / hash_hmac_algos() for compiled JIT/AOT modules (#14909, #18908, #20652, #28750, #30794, php-in-PHP).
 *
 * Always NestedJIT'd via {@see \PHPCompiler\JIT\JitVmHelperLink} (no thin registry fork).
 * NestedJIT-safe: return **inline** list literals (password_algos #9908 shape) — do not fetch
 * {@see \PHPCompiler\ext\standard\HashAlgosRegistry} class consts from this TU.
 * Helper-runtime deps only pull one-level same-dir class refs; `HashAlgosRegistry` lives in
 * `ext/standard/`, so `::ALL_ALGOS` / `::HMAC_ALGOS` were omitted from the unit and thin AOT
 * returned `[]` / `Undefined constant …::ALL_ALGOS` (#30794, re-#28750).
 *
 * Keep list bodies identical to {@see \PHPCompiler\ext\standard\HashAlgosRegistry} (VM SSOT);
 * unit tests assert equality.
 *
 * Return type is `array` (not {@see HashTable}): NestedJIT maps class HashTable to object
 * ABI and TypeErrors on the pointer (#20652). `array` → `__hashtable__*`.
 *
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHash::algos()} / {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_algos) / PHP_FUNCTION(hash_hmac_algos)
 */
final class HashAlgosJitHelper
{
    /**
     * @return list<string>
     */
    public static function algosArgv(): array
    {
        // Inline — must match HashAlgosRegistry ALL_ALGOS list (#30794).
        return [
            'md2',
            'md4',
            'md5',
            'sha1',
            'sha224',
            'sha256',
            'sha384',
            'sha512/224',
            'sha512/256',
            'sha512',
            'sha3-224',
            'sha3-256',
            'sha3-384',
            'sha3-512',
            'ripemd128',
            'ripemd160',
            'ripemd256',
            'ripemd320',
            'whirlpool',
            'tiger128,3',
            'tiger160,3',
            'tiger192,3',
            'tiger128,4',
            'tiger160,4',
            'tiger192,4',
            'snefru',
            'snefru256',
            'gost',
            'gost-crypto',
            'adler32',
            'crc32',
            'crc32b',
            'crc32c',
            'fnv132',
            'fnv1a32',
            'fnv164',
            'fnv1a64',
            'joaat',
            'murmur3a',
            'murmur3c',
            'murmur3f',
            'xxh32',
            'xxh64',
            'xxh3',
            'xxh128',
            'haval128,3',
            'haval160,3',
            'haval192,3',
            'haval224,3',
            'haval256,3',
            'haval128,4',
            'haval160,4',
            'haval192,4',
            'haval224,4',
            'haval256,4',
            'haval128,5',
            'haval160,5',
            'haval192,5',
            'haval224,5',
            'haval256,5',
        ];
    }

    /**
     * @return list<string>
     */
    public static function hmacAlgosArgv(): array
    {
        // Inline — must match HashAlgosRegistry HMAC_ALGOS list (#30794).
        return [
            'md2',
            'md4',
            'md5',
            'sha1',
            'sha224',
            'sha256',
            'sha384',
            'sha512/224',
            'sha512/256',
            'sha512',
            'sha3-224',
            'sha3-256',
            'sha3-384',
            'sha3-512',
            'ripemd128',
            'ripemd160',
            'ripemd256',
            'ripemd320',
            'whirlpool',
            'tiger128,3',
            'tiger160,3',
            'tiger192,3',
            'tiger128,4',
            'tiger160,4',
            'tiger192,4',
            'snefru',
            'snefru256',
            'gost',
            'gost-crypto',
            'haval128,3',
            'haval160,3',
            'haval192,3',
            'haval224,3',
            'haval256,3',
            'haval128,4',
            'haval160,4',
            'haval192,4',
            'haval224,4',
            'haval256,4',
            'haval128,5',
            'haval160,5',
            'haval192,5',
            'haval224,5',
            'haval256,5',
        ];
    }
}
