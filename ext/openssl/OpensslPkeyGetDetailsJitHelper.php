<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * NestedJIT leaf for openssl_pkey_get_details() (#34030 leftover of #33496 / #20240).
 *
 * Returns the details array, or [] on failure (caller boxes false).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_get_details)
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI (#20652).
 */
final class OpensslPkeyGetDetailsJitHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function fromPem(string $pem): array
    {
        if ('' === $pem || !VmOpensslPkeyNative::available()) {
            return [];
        }

        $details = VmOpensslPkeyNative::getDetails($pem);

        return false === $details ? [] : $details;
    }
}
