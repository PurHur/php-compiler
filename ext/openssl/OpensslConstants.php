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
    /** php-src openssl.stub.php — refuse silent \\0 key pad when short (#22326). */
    public const OPENSSL_DONT_ZERO_PAD_KEY = 4;
    /**
     * php-src ext/openssl/openssl.c — TLS server-name extension present (#24084).
     *
     * Registered as 1 when OpenSSL was built without OPENSSL_NO_TLS_SERVER_NAME
     * (same as Zend on the pinned ubuntu-22.04 / OpenSSL 3 image).
     */
    public const OPENSSL_TLSEXT_SERVER_NAME = 1;
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

    /** php-src OPENSSL_CMS_* ← openssl/cms.h CMS_* flags (#6592). */
    public const OPENSSL_CMS_TEXT = 1;
    public const OPENSSL_CMS_NOCERTS = 2;
    public const OPENSSL_CMS_NOSIGS = 12;
    public const OPENSSL_CMS_NOINTERN = 16;
    public const OPENSSL_CMS_NOVERIFY = 32;
    public const OPENSSL_CMS_DETACHED = 64;
    public const OPENSSL_CMS_BINARY = 128;
    public const OPENSSL_CMS_NOATTR = 256;

    /** php-src OPENSSL_ENCODING_* (ext/openssl/openssl.c ENCODING_*). */
    public const OPENSSL_ENCODING_DER = 0;
    public const OPENSSL_ENCODING_SMIME = 1;
    public const OPENSSL_ENCODING_PEM = 2;

    /** php-src X509_PURPOSE_* (openssl/x509v3.h; openssl.stub.php; #20286). */
    public const X509_PURPOSE_SSL_CLIENT = 1;
    public const X509_PURPOSE_SSL_SERVER = 2;
    public const X509_PURPOSE_NS_SSL_SERVER = 3;
    public const X509_PURPOSE_SMIME_SIGN = 4;
    public const X509_PURPOSE_SMIME_ENCRYPT = 5;
    public const X509_PURPOSE_CRL_SIGN = 6;
    public const X509_PURPOSE_ANY = 7;
    public const X509_PURPOSE_OCSP_HELPER = 8;
    public const X509_PURPOSE_TIMESTAMP_SIGN = 9;

    /**
     * php-src ext/openssl/php_openssl.h OPENSSL_DEFAULT_STREAM_CIPHERS (#24070).
     *
     * Fixed cipher-suite string for stream crypto defaults — not from libcrypto.
     */
    public const OPENSSL_DEFAULT_STREAM_CIPHERS =
        'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:'
        .'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:'
        .'DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256:'
        .'ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA384:'
        .'ECDHE-RSA-AES256-SHA:ECDHE-ECDSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:'
        .'DHE-DSS-AES128-SHA256:DHE-RSA-AES256-SHA256:DHE-DSS-AES256-SHA:DHE-RSA-AES256-SHA:AES128-GCM-SHA256:'
        .'AES256-GCM-SHA384:AES128:AES256:HIGH:!SSLv2:!aNULL:!eNULL:!EXPORT:!DES:!MD5:!RC4:!ADH';

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        return self::identityConstants() + [
            'OPENSSL_RAW_DATA' => self::OPENSSL_RAW_DATA,
            'OPENSSL_ZERO_PADDING' => self::OPENSSL_ZERO_PADDING,
            'OPENSSL_DONT_ZERO_PAD_KEY' => self::OPENSSL_DONT_ZERO_PAD_KEY,
            'OPENSSL_TLSEXT_SERVER_NAME' => self::OPENSSL_TLSEXT_SERVER_NAME,
            'OPENSSL_PKCS1_PADDING' => self::OPENSSL_PKCS1_PADDING,
            'OPENSSL_NO_PADDING' => self::OPENSSL_NO_PADDING,
            'OPENSSL_PKCS1_OAEP_PADDING' => self::OPENSSL_PKCS1_OAEP_PADDING,
        ] + self::algorithmConstants() + self::pkcs7Constants() + self::cmsConstants() + self::cipherConstants() + self::x509PurposeConstants();
    }

    /**
     * OpenSSL library identity + default stream cipher list (php-src openssl.stub.php; #24070).
     *
     * VERSION_TEXT / VERSION_NUMBER come from linked libcrypto (same FFI path as encrypt/sign).
     *
     * @return array<string, int|string>
     */
    public static function identityConstants(): array
    {
        $out = [
            'OPENSSL_DEFAULT_STREAM_CIPHERS' => self::OPENSSL_DEFAULT_STREAM_CIPHERS,
        ];
        $text = VmOpensslConfigNative::libraryVersionText();
        if (null !== $text) {
            $out['OPENSSL_VERSION_TEXT'] = $text;
        }
        $number = VmOpensslConfigNative::libraryVersionNumber();
        if (null !== $number) {
            $out['OPENSSL_VERSION_NUMBER'] = $number;
        }

        return $out;
    }

    /** @return array<string, int> */
    public static function x509PurposeConstants(): array
    {
        return [
            'X509_PURPOSE_SSL_CLIENT' => self::X509_PURPOSE_SSL_CLIENT,
            'X509_PURPOSE_SSL_SERVER' => self::X509_PURPOSE_SSL_SERVER,
            'X509_PURPOSE_NS_SSL_SERVER' => self::X509_PURPOSE_NS_SSL_SERVER,
            'X509_PURPOSE_SMIME_SIGN' => self::X509_PURPOSE_SMIME_SIGN,
            'X509_PURPOSE_SMIME_ENCRYPT' => self::X509_PURPOSE_SMIME_ENCRYPT,
            'X509_PURPOSE_CRL_SIGN' => self::X509_PURPOSE_CRL_SIGN,
            'X509_PURPOSE_ANY' => self::X509_PURPOSE_ANY,
            'X509_PURPOSE_OCSP_HELPER' => self::X509_PURPOSE_OCSP_HELPER,
            'X509_PURPOSE_TIMESTAMP_SIGN' => self::X509_PURPOSE_TIMESTAMP_SIGN,
        ];
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
    public static function cmsConstants(): array
    {
        return [
            'OPENSSL_CMS_TEXT' => self::OPENSSL_CMS_TEXT,
            'OPENSSL_CMS_NOCERTS' => self::OPENSSL_CMS_NOCERTS,
            'OPENSSL_CMS_NOSIGS' => self::OPENSSL_CMS_NOSIGS,
            'OPENSSL_CMS_NOINTERN' => self::OPENSSL_CMS_NOINTERN,
            'OPENSSL_CMS_NOVERIFY' => self::OPENSSL_CMS_NOVERIFY,
            'OPENSSL_CMS_DETACHED' => self::OPENSSL_CMS_DETACHED,
            'OPENSSL_CMS_BINARY' => self::OPENSSL_CMS_BINARY,
            'OPENSSL_CMS_NOATTR' => self::OPENSSL_CMS_NOATTR,
            'OPENSSL_ENCODING_DER' => self::OPENSSL_ENCODING_DER,
            'OPENSSL_ENCODING_SMIME' => self::OPENSSL_ENCODING_SMIME,
            'OPENSSL_ENCODING_PEM' => self::OPENSSL_ENCODING_PEM,
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
