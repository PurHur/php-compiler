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

    /**
     * @return array<string, int>
     */
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'OPENSSL_RAW_DATA' => self::OPENSSL_RAW_DATA,
            'OPENSSL_ZERO_PADDING' => self::OPENSSL_ZERO_PADDING,
            'OPENSSL_PKCS1_PADDING' => self::OPENSSL_PKCS1_PADDING,
            'OPENSSL_NO_PADDING' => self::OPENSSL_NO_PADDING,
            'OPENSSL_PKCS1_OAEP_PADDING' => self::OPENSSL_PKCS1_OAEP_PADDING,
        ] + self::algorithmConstants();
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
}
