<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSL VM helpers (php-src ext/openssl/openssl.c; issue #7331).
 */
final class VmOpenssl
{
    /**
     * openssl_cipher_iv_length() — required IV length for a cipher (php-src openssl_cipher_iv_length).
     *
     * Host bridge: delegate to Zend openssl extension when the compiler runs under PHP.
     *
     * @return int|false
     */
    public static function cipher_iv_length(string $cipher_algo): int|false
    {
        if (\function_exists('openssl_cipher_iv_length')) {
            return \openssl_cipher_iv_length($cipher_algo);
        }

        return false;
    }
}
