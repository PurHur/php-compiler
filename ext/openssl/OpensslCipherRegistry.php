<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSL cipher IV lengths without host ext/openssl delegation (#6228, #7331).
 *
 * php-src: ext/openssl/openssl.c — EVP_CIPHER_iv_length() table (OpenSSL 3.x Linux).
 */
final class OpensslCipherRegistry
{
    /** @var array<string, int> */
    private const CIPHER_IV_LENGTHS = [
        'aes-128-cbc' => 16,
        'aes-128-cbc-hmac-sha1' => 16,
        'aes-128-cbc-hmac-sha256' => 16,
        'aes-128-ccm' => 12,
        'aes-128-cfb' => 16,
        'aes-128-cfb1' => 16,
        'aes-128-cfb8' => 16,
        'aes-128-ctr' => 16,
        'aes-128-ecb' => 0,
        'aes-128-gcm' => 12,
        'aes-128-ocb' => 12,
        'aes-128-ofb' => 16,
        'aes-128-wrap' => 8,
        'aes-128-wrap-pad' => 4,
        'aes-128-xts' => 16,
        'aes-192-cbc' => 16,
        'aes-192-ccm' => 12,
        'aes-192-cfb' => 16,
        'aes-192-cfb1' => 16,
        'aes-192-cfb8' => 16,
        'aes-192-ctr' => 16,
        'aes-192-ecb' => 0,
        'aes-192-gcm' => 12,
        'aes-192-ocb' => 12,
        'aes-192-ofb' => 16,
        'aes-192-wrap' => 8,
        'aes-192-wrap-pad' => 4,
        'aes-256-cbc' => 16,
        'aes-256-cbc-hmac-sha1' => 16,
        'aes-256-cbc-hmac-sha256' => 16,
        'aes-256-ccm' => 12,
        'aes-256-cfb' => 16,
        'aes-256-cfb1' => 16,
        'aes-256-cfb8' => 16,
        'aes-256-ctr' => 16,
        'aes-256-ecb' => 0,
        'aes-256-gcm' => 12,
        'aes-256-ocb' => 12,
        'aes-256-ofb' => 16,
        'aes-256-wrap' => 8,
        'aes-256-wrap-pad' => 4,
        'aes-256-xts' => 16,
        'aria-128-cbc' => 16,
        'aria-128-ccm' => 12,
        'aria-128-cfb' => 16,
        'aria-128-cfb1' => 16,
        'aria-128-cfb8' => 16,
        'aria-128-ctr' => 16,
        'aria-128-ecb' => 0,
        'aria-128-gcm' => 12,
        'aria-128-ofb' => 16,
        'aria-192-cbc' => 16,
        'aria-192-ccm' => 12,
        'aria-192-cfb' => 16,
        'aria-192-cfb1' => 16,
        'aria-192-cfb8' => 16,
        'aria-192-ctr' => 16,
        'aria-192-ecb' => 0,
        'aria-192-gcm' => 12,
        'aria-192-ofb' => 16,
        'aria-256-cbc' => 16,
        'aria-256-ccm' => 12,
        'aria-256-cfb' => 16,
        'aria-256-cfb1' => 16,
        'aria-256-cfb8' => 16,
        'aria-256-ctr' => 16,
        'aria-256-ecb' => 0,
        'aria-256-gcm' => 12,
        'aria-256-ofb' => 16,
        'camellia-128-cbc' => 16,
        'camellia-128-cfb' => 16,
        'camellia-128-cfb1' => 16,
        'camellia-128-cfb8' => 16,
        'camellia-128-ctr' => 16,
        'camellia-128-ecb' => 0,
        'camellia-128-ofb' => 16,
        'camellia-192-cbc' => 16,
        'camellia-192-cfb' => 16,
        'camellia-192-cfb1' => 16,
        'camellia-192-cfb8' => 16,
        'camellia-192-ctr' => 16,
        'camellia-192-ecb' => 0,
        'camellia-192-ofb' => 16,
        'camellia-256-cbc' => 16,
        'camellia-256-cfb' => 16,
        'camellia-256-cfb1' => 16,
        'camellia-256-cfb8' => 16,
        'camellia-256-ctr' => 16,
        'camellia-256-ecb' => 0,
        'camellia-256-ofb' => 16,
        'chacha20' => 16,
        'chacha20-poly1305' => 12,
        'des-ede-cbc' => 8,
        'des-ede-cfb' => 8,
        'des-ede-ecb' => 0,
        'des-ede-ofb' => 8,
        'des-ede3-cbc' => 8,
        'des-ede3-cfb' => 8,
        'des-ede3-cfb1' => 8,
        'des-ede3-cfb8' => 8,
        'des-ede3-ecb' => 0,
        'des-ede3-ofb' => 8,
        'des3-wrap' => 0,
        'sm4-cbc' => 16,
        'sm4-cfb' => 16,
        'sm4-ctr' => 16,
        'sm4-ecb' => 0,
        'sm4-ofb' => 16,
    ];

    /**
     * Cipher names listed by openssl_get_cipher_methods() (OpenSSL 3.x Linux).
     * Sorted keys of {@see CIPHER_IV_LENGTHS} — NestedJIT-safe list SSOT (#30148 / hash_algos #28750).
     *
     * @var list<string>
     */
    public const CIPHER_METHODS = [
        'aes-128-cbc',
        'aes-128-cbc-hmac-sha1',
        'aes-128-cbc-hmac-sha256',
        'aes-128-ccm',
        'aes-128-cfb',
        'aes-128-cfb1',
        'aes-128-cfb8',
        'aes-128-ctr',
        'aes-128-ecb',
        'aes-128-gcm',
        'aes-128-ocb',
        'aes-128-ofb',
        'aes-128-wrap',
        'aes-128-wrap-pad',
        'aes-128-xts',
        'aes-192-cbc',
        'aes-192-ccm',
        'aes-192-cfb',
        'aes-192-cfb1',
        'aes-192-cfb8',
        'aes-192-ctr',
        'aes-192-ecb',
        'aes-192-gcm',
        'aes-192-ocb',
        'aes-192-ofb',
        'aes-192-wrap',
        'aes-192-wrap-pad',
        'aes-256-cbc',
        'aes-256-cbc-hmac-sha1',
        'aes-256-cbc-hmac-sha256',
        'aes-256-ccm',
        'aes-256-cfb',
        'aes-256-cfb1',
        'aes-256-cfb8',
        'aes-256-ctr',
        'aes-256-ecb',
        'aes-256-gcm',
        'aes-256-ocb',
        'aes-256-ofb',
        'aes-256-wrap',
        'aes-256-wrap-pad',
        'aes-256-xts',
        'aria-128-cbc',
        'aria-128-ccm',
        'aria-128-cfb',
        'aria-128-cfb1',
        'aria-128-cfb8',
        'aria-128-ctr',
        'aria-128-ecb',
        'aria-128-gcm',
        'aria-128-ofb',
        'aria-192-cbc',
        'aria-192-ccm',
        'aria-192-cfb',
        'aria-192-cfb1',
        'aria-192-cfb8',
        'aria-192-ctr',
        'aria-192-ecb',
        'aria-192-gcm',
        'aria-192-ofb',
        'aria-256-cbc',
        'aria-256-ccm',
        'aria-256-cfb',
        'aria-256-cfb1',
        'aria-256-cfb8',
        'aria-256-ctr',
        'aria-256-ecb',
        'aria-256-gcm',
        'aria-256-ofb',
        'camellia-128-cbc',
        'camellia-128-cfb',
        'camellia-128-cfb1',
        'camellia-128-cfb8',
        'camellia-128-ctr',
        'camellia-128-ecb',
        'camellia-128-ofb',
        'camellia-192-cbc',
        'camellia-192-cfb',
        'camellia-192-cfb1',
        'camellia-192-cfb8',
        'camellia-192-ctr',
        'camellia-192-ecb',
        'camellia-192-ofb',
        'camellia-256-cbc',
        'camellia-256-cfb',
        'camellia-256-cfb1',
        'camellia-256-cfb8',
        'camellia-256-ctr',
        'camellia-256-ecb',
        'camellia-256-ofb',
        'chacha20',
        'chacha20-poly1305',
        'des-ede-cbc',
        'des-ede-cfb',
        'des-ede-ecb',
        'des-ede-ofb',
        'des-ede3-cbc',
        'des-ede3-cfb',
        'des-ede3-cfb1',
        'des-ede3-cfb8',
        'des-ede3-ecb',
        'des-ede3-ofb',
        'des3-wrap',
        'sm4-cbc',
        'sm4-cfb',
        'sm4-ctr',
        'sm4-ecb',
        'sm4-ofb',
    ];

    /**
     * Digest names listed by openssl_get_md_methods() (OpenSSL 3.x Linux).
     *
     * @var list<string>
     */
    public const MD_METHODS = [
        'blake2b512',
        'blake2s256',
        'md4',
        'md5',
        'md5-sha1',
        'ripemd160',
        'sha1',
        'sha224',
        'sha256',
        'sha3-224',
        'sha3-256',
        'sha3-384',
        'sha3-512',
        'sha384',
        'sha512',
        'sha512-224',
        'sha512-256',
        'shake128',
        'shake256',
        'sm3',
        'whirlpool',
    ];

    /** Digests openssl_digest() can compute via VmHashNative today. */
    private const DIGEST_IMPLEMENTED = ['md5', 'sha1', 'sha256'];

    /** @var array<string, int> EVP_CIPHER_key_length() (OpenSSL 3.x Linux; #6522). */
    private const CIPHER_KEY_LENGTHS = [
        'aes-128-cbc' => 16,
        'aes-128-cbc-hmac-sha1' => 16,
        'aes-128-cbc-hmac-sha256' => 16,
        'aes-128-ccm' => 16,
        'aes-128-cfb' => 16,
        'aes-128-cfb1' => 16,
        'aes-128-cfb8' => 16,
        'aes-128-ctr' => 16,
        'aes-128-ecb' => 16,
        'aes-128-gcm' => 16,
        'aes-128-ocb' => 16,
        'aes-128-ofb' => 16,
        'aes-128-xts' => 32,
        'aes-192-cbc' => 24,
        'aes-192-ccm' => 24,
        'aes-192-cfb' => 24,
        'aes-192-cfb1' => 24,
        'aes-192-cfb8' => 24,
        'aes-192-ctr' => 24,
        'aes-192-ecb' => 24,
        'aes-192-gcm' => 24,
        'aes-192-ocb' => 24,
        'aes-192-ofb' => 24,
        'aes-256-cbc' => 32,
        'aes-256-cbc-hmac-sha1' => 32,
        'aes-256-cbc-hmac-sha256' => 32,
        'aes-256-ccm' => 32,
        'aes-256-cfb' => 32,
        'aes-256-cfb1' => 32,
        'aes-256-cfb8' => 32,
        'aes-256-ctr' => 32,
        'aes-256-ecb' => 32,
        'aes-256-gcm' => 32,
        'aes-256-ocb' => 32,
        'aes-256-ofb' => 32,
        'aes-256-xts' => 64,
        'aria-128-cbc' => 16,
        'aria-128-ccm' => 16,
        'aria-128-cfb' => 16,
        'aria-128-cfb1' => 16,
        'aria-128-cfb8' => 16,
        'aria-128-ctr' => 16,
        'aria-128-ecb' => 16,
        'aria-128-gcm' => 16,
        'aria-128-ofb' => 16,
        'aria-192-cbc' => 24,
        'aria-192-ccm' => 24,
        'aria-192-cfb' => 24,
        'aria-192-cfb1' => 24,
        'aria-192-cfb8' => 24,
        'aria-192-ctr' => 24,
        'aria-192-ecb' => 24,
        'aria-192-gcm' => 24,
        'aria-192-ofb' => 24,
        'aria-256-cbc' => 32,
        'aria-256-ccm' => 32,
        'aria-256-cfb' => 32,
        'aria-256-cfb1' => 32,
        'aria-256-cfb8' => 32,
        'aria-256-ctr' => 32,
        'aria-256-ecb' => 32,
        'aria-256-gcm' => 32,
        'aria-256-ofb' => 32,
        'camellia-128-cbc' => 16,
        'camellia-128-cfb' => 16,
        'camellia-128-cfb1' => 16,
        'camellia-128-cfb8' => 16,
        'camellia-128-ctr' => 16,
        'camellia-128-ecb' => 16,
        'camellia-128-ofb' => 16,
        'camellia-192-cbc' => 24,
        'camellia-192-cfb' => 24,
        'camellia-192-cfb1' => 24,
        'camellia-192-cfb8' => 24,
        'camellia-192-ctr' => 24,
        'camellia-192-ecb' => 24,
        'camellia-192-ofb' => 24,
        'camellia-256-cbc' => 32,
        'camellia-256-cfb' => 32,
        'camellia-256-cfb1' => 32,
        'camellia-256-cfb8' => 32,
        'camellia-256-ctr' => 32,
        'camellia-256-ecb' => 32,
        'camellia-256-ofb' => 32,
        'chacha20' => 32,
        'chacha20-poly1305' => 32,
        'des-ede-cbc' => 16,
        'des-ede-cfb' => 16,
        'des-ede-ecb' => 16,
        'des-ede-ofb' => 16,
        'des-ede3-cbc' => 24,
        'des-ede3-cfb' => 24,
        'des-ede3-cfb1' => 24,
        'des-ede3-cfb8' => 24,
        'des-ede3-ecb' => 24,
        'des-ede3-ofb' => 24,
        'des3-wrap' => 24,
        'sm4-cbc' => 16,
        'sm4-cfb' => 16,
        'sm4-ctr' => 16,
        'sm4-ecb' => 16,
        'sm4-ofb' => 16,
    ];

    public static function cipherIvLength(string $cipherAlgo): int|false
    {
        $key = strtolower($cipherAlgo);

        return self::CIPHER_IV_LENGTHS[$key] ?? false;
    }

    public static function cipherKeyLength(string $cipherAlgo): int|false
    {
        $key = strtolower($cipherAlgo);

        return self::CIPHER_KEY_LENGTHS[$key] ?? false;
    }

    /**
     * AEAD ciphers that use authentication tags (php-src php_openssl_load_cipher_mode; #21135).
     */
    public static function isAeadCipher(string $cipherAlgo): bool
    {
        $key = strtolower($cipherAlgo);
        if ('chacha20-poly1305' === $key) {
            return true;
        }

        return str_ends_with($key, '-gcm')
            || str_ends_with($key, '-ccm')
            || str_ends_with($key, '-ocb');
    }

    /** CCM (and similar) need tag length set before encrypt (php-src set_tag_length_when_encrypting). */
    public static function aeadSetsTagLengthWhenEncrypting(string $cipherAlgo): bool
    {
        return str_ends_with(strtolower($cipherAlgo), '-ccm');
    }

    /** @return list<string> */
    public static function cipherMethods(bool $aliases = false): array
    {
        unset($aliases);

        return self::CIPHER_METHODS;
    }

    /** @return list<string> */
    public static function mdMethods(bool $aliases = false): array
    {
        unset($aliases);

        return self::MD_METHODS;
    }

    public static function digestImplemented(string $method): bool
    {
        return in_array(strtolower($method), self::DIGEST_IMPLEMENTED, true);
    }
}
