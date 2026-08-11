<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_get_cipher_methods() / openssl_get_md_methods() for compiled JIT/AOT (#21103, #30148, php-in-PHP).
 *
 * Always NestedJIT'd via {@see \PHPCompiler\JIT\JitVmHelperLink} (no thin registry fork).
 * NestedJIT-safe: return {@see OpensslCipherRegistry} list consts (hash_algos #28750 / password_algos #9908 shape) —
 * no NestedJIT registry kernel / LLVM hashtable-build leaf re-entry (#30148).
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

        return OpensslCipherRegistry::CIPHER_METHODS;
    }

    /**
     * @return list<string>
     */
    public static function mdMethodsArgv(int $aliases): array
    {
        unset($aliases);

        return OpensslCipherRegistry::MD_METHODS;
    }
}
