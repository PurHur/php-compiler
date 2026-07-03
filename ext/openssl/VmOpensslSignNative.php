<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * EVP_DigestSign / EVP_DigestVerify via libcrypto FFI (#11535, php-src ext/openssl/openssl.c).
 */
final class VmOpensslSignNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function sign(string $data, string $privateKeyPem, string $digestName): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $privateKeyPem);
        if (null === $pkey) {
            return false;
        }

        $md = $ffi->EVP_get_digestbyname($digestName);
        if (null === $md) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        $ctx = $ffi->EVP_MD_CTX_new();
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_DigestSignInit($ctx, null, $md, null, $pkey)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_DigestSignUpdate($ctx, $data, \strlen($data))) {
                return false;
            }

            $siglen = $ffi->new('size_t');
            $siglen->cdata = 0;
            if (1 !== (int) $ffi->EVP_DigestSignFinal($ctx, null, \FFI::addr($siglen))) {
                return false;
            }

            $length = (int) $siglen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_DigestSignFinal($ctx, $buf, \FFI::addr($siglen))) {
                return false;
            }

            return \FFI::string($buf, (int) $siglen->cdata);
        } finally {
            $ffi->EVP_MD_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @return int 1 valid, 0 invalid, -1 error (php-src openssl_verify)
     */
    public static function verify(string $data, string $signature, string $publicKeyPem, string $digestName): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $pkey = self::readPublicKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return -1;
        }

        $md = $ffi->EVP_get_digestbyname($digestName);
        if (null === $md) {
            $ffi->EVP_PKEY_free($pkey);

            return -1;
        }

        $ctx = $ffi->EVP_MD_CTX_new();
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return -1;
        }

        try {
            if (1 !== (int) $ffi->EVP_DigestVerifyInit($ctx, null, $md, null, $pkey)) {
                return -1;
            }
            if (1 !== (int) $ffi->EVP_DigestVerifyUpdate($ctx, $data, \strlen($data))) {
                return -1;
            }

            $sigLen = \strlen($signature);
            if (0 === $sigLen) {
                return 0;
            }

            $sigBuf = $ffi->new("unsigned char[{$sigLen}]");
            \FFI::memcpy($sigBuf, $signature, $sigLen);

            $result = (int) $ffi->EVP_DigestVerifyFinal($ctx, $sigBuf, $sigLen);
            if (1 === $result) {
                return 1;
            }
            if (0 === $result) {
                return 0;
            }

            return -1;
        } finally {
            $ffi->EVP_MD_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPrivateKey($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPublicKey($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            $pub = $ffi->PEM_read_bio_PUBKEY($bio, null, null, null);
            if (null !== $pub) {
                return $pub;
            }

            return $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
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
typedef struct bio_st BIO;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct evp_md_st EVP_MD;
typedef struct evp_md_ctx_st EVP_MD_CTX;

BIO *BIO_new_mem_buf(const void *buf, int len);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
void EVP_PKEY_free(EVP_PKEY *pkey);
EVP_MD_CTX *EVP_MD_CTX_new(void);
void EVP_MD_CTX_free(EVP_MD_CTX *ctx);
const EVP_MD *EVP_get_digestbyname(const char *name);
int EVP_DigestSignInit(EVP_MD_CTX *ctx, void **pctx, const EVP_MD *type, void *e, EVP_PKEY *pkey);
int EVP_DigestSignUpdate(EVP_MD_CTX *ctx, const void *d, size_t cnt);
int EVP_DigestSignFinal(EVP_MD_CTX *ctx, unsigned char *sig, size_t *siglen);
int EVP_DigestVerifyInit(EVP_MD_CTX *ctx, void **pctx, const EVP_MD *type, void *e, EVP_PKEY *pkey);
int EVP_DigestVerifyUpdate(EVP_MD_CTX *ctx, const void *d, size_t cnt);
int EVP_DigestVerifyFinal(EVP_MD_CTX *ctx, const unsigned char *sig, size_t siglen);
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
