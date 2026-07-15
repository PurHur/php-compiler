<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSL extension constants (php-src ext/openssl/openssl.stub.php; issue #7000).
 */
final class OpensslConstants
{
    public const OPENSSL_RAW_DATA = 1;
    public const OPENSSL_ZERO_PADDING = 2;
    public const OPENSSL_PKCS1_PADDING = 1;
    public const OPENSSL_NO_PADDING = 3;
    public const OPENSSL_PKCS1_OAEP_PADDING = 4;

    /** php-src ext/openssl/openssl.c — digest/signature algorithm ids. */
    public const OPENSSL_ALGO_SHA1 = 1;
    public const OPENSSL_ALGO_MD5 = 2;
    public const OPENSSL_ALGO_MD4 = 3;
    public const OPENSSL_ALGO_SHA224 = 6;
    public const OPENSSL_ALGO_SHA256 = 7;
    public const OPENSSL_ALGO_SHA384 = 8;
    public const OPENSSL_ALGO_SHA512 = 9;
    public const OPENSSL_ALGO_RMD160 = 10;

    /** php-src ext/openssl/xp.c — asymmetric key types. */
    public const OPENSSL_KEYTYPE_RSA = 0;
    public const OPENSSL_KEYTYPE_DSA = 1;
    public const OPENSSL_KEYTYPE_DH = 2;
    public const OPENSSL_KEYTYPE_EC = 3;

    /** php-src OPENSSL_CIPHER_* — PKCS7 encrypt cipher ids (openssl.stub.php). */
    public const OPENSSL_CIPHER_RC2_40 = 0;
    public const OPENSSL_CIPHER_RC2_128 = 1;
    public const OPENSSL_CIPHER_RC2_64 = 2;
    public const OPENSSL_CIPHER_DES = 3;
    public const OPENSSL_CIPHER_3DES = 4;
    public const OPENSSL_CIPHER_AES_128_CBC = 5;
    public const OPENSSL_CIPHER_AES_192_CBC = 6;
    public const OPENSSL_CIPHER_AES_256_CBC = 7;

    /** php-src PKCS7_* flags (openssl/pkcs7.h). */
    public const PKCS7_TEXT = 1;
    public const PKCS7_NOCERTS = 2;
    public const PKCS7_NOSIGS = 4;
    public const PKCS7_NOCHAIN = 8;
    public const PKCS7_NOINTERN = 16;
    public const PKCS7_NOVERIFY = 32;
    public const PKCS7_DETACHED = 64;
    public const PKCS7_BINARY = 128;
    public const PKCS7_NOATTR = 256;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'OPENSSL_RAW_DATA' => self::OPENSSL_RAW_DATA,
            'OPENSSL_ZERO_PADDING' => self::OPENSSL_ZERO_PADDING,
            'OPENSSL_PKCS1_PADDING' => self::OPENSSL_PKCS1_PADDING,
            'OPENSSL_NO_PADDING' => self::OPENSSL_NO_PADDING,
            'OPENSSL_PKCS1_OAEP_PADDING' => self::OPENSSL_PKCS1_OAEP_PADDING,
        ] + self::algorithmConstants() + self::pkcs7Constants() + self::cipherConstants();
    }

    public static function algorithmConstants(): array
    {
        return [
            'OPENSSL_ALGO_SHA1' => self::OPENSSL_ALGO_SHA1,
            'OPENSSL_ALGO_MD5' => self::OPENSSL_ALGO_MD5,
            'OPENSSL_ALGO_MD4' => self::OPENSSL_ALGO_MD4,
            'OPENSSL_ALGO_SHA224' => self::OPENSSL_ALGO_SHA224,
            'OPENSSL_ALGO_SHA256' => self::OPENSSL_ALGO_SHA256,
            'OPENSSL_ALGO_SHA384' => self::OPENSSL_ALGO_SHA384,
            'OPENSSL_ALGO_SHA512' => self::OPENSSL_ALGO_SHA512,
            'OPENSSL_ALGO_RMD160' => self::OPENSSL_ALGO_RMD160,
            'OPENSSL_KEYTYPE_RSA' => self::OPENSSL_KEYTYPE_RSA,
            'OPENSSL_KEYTYPE_DSA' => self::OPENSSL_KEYTYPE_DSA,
            'OPENSSL_KEYTYPE_DH' => self::OPENSSL_KEYTYPE_DH,
            'OPENSSL_KEYTYPE_EC' => self::OPENSSL_KEYTYPE_EC,
        ];
    }

    /** @return array<string, int> */
    public static function pkcs7Constants(): array
    {
        return [
            'PKCS7_TEXT' => self::PKCS7_TEXT,
            'PKCS7_NOCERTS' => self::PKCS7_NOCERTS,
            'PKCS7_NOSIGS' => self::PKCS7_NOSIGS,
            'PKCS7_NOCHAIN' => self::PKCS7_NOCHAIN,
            'PKCS7_NOINTERN' => self::PKCS7_NOINTERN,
            'PKCS7_NOVERIFY' => self::PKCS7_NOVERIFY,
            'PKCS7_DETACHED' => self::PKCS7_DETACHED,
            'PKCS7_BINARY' => self::PKCS7_BINARY,
            'PKCS7_NOATTR' => self::PKCS7_NOATTR,
        ];
    }

    /** @return array<string, int> */
    public static function cipherConstants(): array
    {
        return [
            'OPENSSL_CIPHER_RC2_40' => self::OPENSSL_CIPHER_RC2_40,
            'OPENSSL_CIPHER_RC2_128' => self::OPENSSL_CIPHER_RC2_128,
            'OPENSSL_CIPHER_RC2_64' => self::OPENSSL_CIPHER_RC2_64,
            'OPENSSL_CIPHER_DES' => self::OPENSSL_CIPHER_DES,
            'OPENSSL_CIPHER_3DES' => self::OPENSSL_CIPHER_3DES,
            'OPENSSL_CIPHER_AES_128_CBC' => self::OPENSSL_CIPHER_AES_128_CBC,
            'OPENSSL_CIPHER_AES_192_CBC' => self::OPENSSL_CIPHER_AES_192_CBC,
            'OPENSSL_CIPHER_AES_256_CBC' => self::OPENSSL_CIPHER_AES_256_CBC,
        ];
    }
}
