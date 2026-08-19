<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_get_cipher_methods() / openssl_get_md_methods() for compiled JIT/AOT (#21103, #30148, php-in-PHP).
 *
 * Always NestedJIT'd via {@see \PHPCompiler\JIT\JitVmHelperLink} (no thin registry fork).
 * NestedJIT-safe: return **inline** list literals (hash_algos #30794 / password_algos #9908 shape) —
 * do not fetch {@see OpensslCipherRegistry} class consts from this TU. Helper-runtime units omit
 * same-dir class const initializers from dependency TUs, so `::CIPHER_METHODS` / `::MD_METHODS`
 * were undefined at thin-AOT runtime (`[]` + fatal) (#32650, re-#30148).
 *
 * Keep list bodies identical to {@see OpensslCipherRegistry::CIPHER_METHODS} /
 * {@see OpensslCipherRegistry::MD_METHODS} (VM SSOT); unit tests assert equality.
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI (#20652).
 *
 * SSOT for VM: {@see VmOpenssl::cipherMethods()} / {@see VmOpenssl::mdMethods()}
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_get_cipher_methods) / openssl_get_md_methods
 */
final class OpensslMethodsJitHelper
{
    /**
     * @return list<string>
     */
    public static function cipherMethodsArgv(int $aliases): array
    {
        unset($aliases);

        // Inline — must match OpensslCipherRegistry::CIPHER_METHODS (#32650).
        return [
            'aes-128-cbc', 'aes-128-cbc-hmac-sha1', 'aes-128-cbc-hmac-sha256', 'aes-128-ccm',
            'aes-128-cfb', 'aes-128-cfb1', 'aes-128-cfb8', 'aes-128-ctr',
            'aes-128-ecb', 'aes-128-gcm', 'aes-128-ocb', 'aes-128-ofb',
            'aes-128-wrap', 'aes-128-wrap-pad', 'aes-128-xts', 'aes-192-cbc',
            'aes-192-ccm', 'aes-192-cfb', 'aes-192-cfb1', 'aes-192-cfb8',
            'aes-192-ctr', 'aes-192-ecb', 'aes-192-gcm', 'aes-192-ocb',
            'aes-192-ofb', 'aes-192-wrap', 'aes-192-wrap-pad', 'aes-256-cbc',
            'aes-256-cbc-hmac-sha1', 'aes-256-cbc-hmac-sha256', 'aes-256-ccm', 'aes-256-cfb',
            'aes-256-cfb1', 'aes-256-cfb8', 'aes-256-ctr', 'aes-256-ecb',
            'aes-256-gcm', 'aes-256-ocb', 'aes-256-ofb', 'aes-256-wrap',
            'aes-256-wrap-pad', 'aes-256-xts', 'aria-128-cbc', 'aria-128-ccm',
            'aria-128-cfb', 'aria-128-cfb1', 'aria-128-cfb8', 'aria-128-ctr',
            'aria-128-ecb', 'aria-128-gcm', 'aria-128-ofb', 'aria-192-cbc',
            'aria-192-ccm', 'aria-192-cfb', 'aria-192-cfb1', 'aria-192-cfb8',
            'aria-192-ctr', 'aria-192-ecb', 'aria-192-gcm', 'aria-192-ofb',
            'aria-256-cbc', 'aria-256-ccm', 'aria-256-cfb', 'aria-256-cfb1',
            'aria-256-cfb8', 'aria-256-ctr', 'aria-256-ecb', 'aria-256-gcm',
            'aria-256-ofb', 'camellia-128-cbc', 'camellia-128-cfb', 'camellia-128-cfb1',
            'camellia-128-cfb8', 'camellia-128-ctr', 'camellia-128-ecb', 'camellia-128-ofb',
            'camellia-192-cbc', 'camellia-192-cfb', 'camellia-192-cfb1', 'camellia-192-cfb8',
            'camellia-192-ctr', 'camellia-192-ecb', 'camellia-192-ofb', 'camellia-256-cbc',
            'camellia-256-cfb', 'camellia-256-cfb1', 'camellia-256-cfb8', 'camellia-256-ctr',
            'camellia-256-ecb', 'camellia-256-ofb', 'chacha20', 'chacha20-poly1305',
            'des-ede-cbc', 'des-ede-cfb', 'des-ede-ecb', 'des-ede-ofb',
            'des-ede3-cbc', 'des-ede3-cfb', 'des-ede3-cfb1', 'des-ede3-cfb8',
            'des-ede3-ecb', 'des-ede3-ofb', 'des3-wrap', 'sm4-cbc',
            'sm4-cfb', 'sm4-ctr', 'sm4-ecb', 'sm4-ofb',
        ];
    }

    /**
     * @return list<string>
     */
    public static function mdMethodsArgv(int $aliases): array
    {
        unset($aliases);

        // Inline — must match OpensslCipherRegistry::MD_METHODS (#32650).
        return [
            'blake2b512', 'blake2s256', 'md4', 'md5',
            'md5-sha1', 'ripemd160', 'sha1', 'sha224',
            'sha256', 'sha3-224', 'sha3-256', 'sha3-384',
            'sha3-512', 'sha384', 'sha512', 'sha512-224',
            'sha512-256', 'shake128', 'shake256', 'sm3',
            'whirlpool',
        ];
    }
}
