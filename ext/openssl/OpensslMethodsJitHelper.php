<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_get_cipher_methods() / openssl_get_md_methods() for compiled JIT/AOT (#21103, php-in-PHP).
 *
 * Kernel path: {@see phpc_openssl_cipher_methods_kernel} / {@see phpc_openssl_md_methods_kernel}
 * (hash_algos #20652 shape — NestedJIT leaf avoids OpensslCipherRegistry under user-script AOT).
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

        return \phpc_openssl_cipher_methods_kernel();
    }

    /**
     * @return list<string>
     */
    public static function mdMethodsArgv(int $aliases): array
    {
        unset($aliases);

        return \phpc_openssl_md_methods_kernel();
    }
}
