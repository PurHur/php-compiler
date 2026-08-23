<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * NestedJIT leaf for openssl_pkey_new() keygen (#34015 leftover of #33530 / #6295).
 *
 * Returns PEM private key material, or '' on failure (caller boxes false).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new) / php_openssl_generate_private_key
 */
final class OpensslPkeyNewJitHelper
{
    /**
     * @param int    $bits  RSA/DSA/DH bit length (ignored for EC)
     * @param int    $type  OpensslConstants::OPENSSL_KEYTYPE_*
     * @param string $curve EC curve short name (required when type is EC)
     */
    public static function generatePem(int $bits, int $type, string $curve = ''): string
    {
        if (!VmOpensslPkeyNative::available()) {
            return '';
        }

        $pem = match ($type) {
            OpensslConstants::OPENSSL_KEYTYPE_RSA => VmOpensslPkeyNative::generateRsa($bits),
            OpensslConstants::OPENSSL_KEYTYPE_EC => ('' === $curve)
                ? false
                : VmOpensslPkeyNative::generateEc($curve),
            OpensslConstants::OPENSSL_KEYTYPE_DH => VmOpensslPkeyNative::generateDh($bits),
            // DSA keygen is not exposed via VmOpensslPkeyNative in this build — soft-fail like VM.
            OpensslConstants::OPENSSL_KEYTYPE_DSA => false,
            default => false,
        };

        return \is_string($pem) ? $pem : '';
    }
}
