<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * Symmetric EVP cipher encrypt/decrypt via libcrypto FFI (php-src ext/openssl/openssl.c; #18594).
 */
final class VmOpensslCipherNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function encrypt(
        string $data,
        string $cipherAlgo,
        string $key,
        string $iv,
        bool $zeroPadding
    ): string|false {
        return self::cipher($cipherAlgo, $key, $iv, $data, true, $zeroPadding);
    }

    public static function decrypt(
        string $data,
        string $cipherAlgo,
        string $key,
        string $iv,
        bool $zeroPadding
    ): string|false {
        return self::cipher($cipherAlgo, $key, $iv, $data, false, $zeroPadding);
    }

    private static function cipher(
        string $cipherAlgo,
        string $key,
        string $iv,
        string $data,
        bool $encrypt,
        bool $zeroPadding
    ): string|false {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $cipher = $ffi->EVP_get_cipherbyname($cipherAlgo);
        if (null === $cipher) {
            return false;
        }

        $ivLen = OpensslCipherRegistry::cipherIvLength($cipherAlgo);
        if (false === $ivLen) {
            return false;
        }
        if ($ivLen > 0 && \strlen($iv) !== $ivLen) {
            return false;
        }

        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $keyLen || $keyLen <= 0) {
            return false;
        }
        if (\strlen($key) !== $keyLen) {
            return false;
        }

        $dataLen = \strlen($data);
        $outBufLen = $encrypt ? $dataLen + 32 : $dataLen;
        if ($outBufLen <= 0) {
            $outBufLen = 32;
        }
        $outBuf = $ffi->new("unsigned char[{$outBufLen}]");

        $keyBuf = $ffi->new("unsigned char[{$keyLen}]");
        \FFI::memcpy($keyBuf, $key, $keyLen);

        $ivBuf = null;
        if ($ivLen > 0) {
            $ivBuf = $ffi->new("unsigned char[{$ivLen}]");
            \FFI::memcpy($ivBuf, $iv, $ivLen);
        }

        $ctx = $ffi->EVP_CIPHER_CTX_new();
        if (null === $ctx) {
            return false;
        }

        try {
            if ($zeroPadding) {
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_set_padding($ctx, 0)) {
                    return false;
                }
            }

            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptInit_ex($ctx, $cipher, null, $keyBuf, $ivBuf)) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptInit_ex($ctx, $cipher, null, $keyBuf, $ivBuf)) {
                    return false;
                }
            }

            $len1 = $ffi->new('int');
            $len1->cdata = 0;
            $inBuf = $ffi->new("unsigned char[{$dataLen}]");
            \FFI::memcpy($inBuf, $data, $dataLen);

            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                    return false;
                }
            }

            $len2 = $ffi->new('int');
            $len2->cdata = 0;
            $offset = (int) $len1->cdata;
            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                    return false;
                }
            }

            $totalLen = $offset + (int) $len2->cdata;
            if ($totalLen < 0) {
                return false;
            }

            return \FFI::string($outBuf, $totalLen);
        } finally {
            $ffi->EVP_CIPHER_CTX_free($ctx);
        }
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct evp_cipher_ctx_st EVP_CIPHER_CTX;

const EVP_CIPHER *EVP_get_cipherbyname(const char *name);
EVP_CIPHER_CTX *EVP_CIPHER_CTX_new(void);
void EVP_CIPHER_CTX_free(EVP_CIPHER_CTX *ctx);
int EVP_CIPHER_CTX_set_padding(EVP_CIPHER_CTX *ctx, int padding);
int EVP_EncryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_EncryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_EncryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
int EVP_DecryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_DecryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_DecryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
CDEF;

        foreach (['libcrypto.so.3', 'libcrypto.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
