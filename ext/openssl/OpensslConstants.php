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

    /** php-src ext/openssl/openssl.c — digest/signature algorithm ids. */
    public const OPENSSL_ALGO_SHA1 = 1;
    public const OPENSSL_ALGO_MD5 = 2;
    public const OPENSSL_ALGO_MD4 = 3;
    public const OPENSSL_ALGO_SHA224 = 6;
    public const OPENSSL_ALGO_SHA256 = 7;
    public const OPENSSL_ALGO_SHA384 = 8;
    public const OPENSSL_ALGO_SHA512 = 9;
    public const OPENSSL_ALGO_RMD160 = 10;

    /**
     * @return array<string, int>
     */
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
        ];
    }
}
