<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * NestedJIT leaf for openssl_pkey_get_details() (#34030 leftover of #33496 / #20240).
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI (#20652).
 * Null means soft-fail (caller boxes false) — peer GetHeadersJitHelper.
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_get_details)
 */
final class OpensslPkeyGetDetailsJitHelper
{
    /**
     * @return array{bits: int, key: string, type: int, rsa?: array<string, string>, ec?: array<string, string>, dh?: array<string, string>}|null
     */
    public static function fromPem(string $pem): ?array
    {
        if ('' === $pem || !VmOpensslPkeyNative::available()) {
            return null;
        }

        $details = VmOpensslPkeyNative::getDetails($pem);
        if (false === $details) {
            return null;
        }

        return $details;
    }
}
