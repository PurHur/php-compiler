<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * NETSCAPE SPKAC helpers via libcrypto FFI (php-src ext/openssl/openssl.c; #8690).
 */
final class VmOpensslSpkiNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function spkiNew(string $privateKeyPem, string $challenge, string $digestName): string|false
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

        $spki = $ffi->NETSCAPE_SPKI_new();
        if (null === $spki) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if ('' !== $challenge
                && !(bool) (int) $ffi->ASN1_STRING_set($spki->spkac->challenge, $challenge, \strlen($challenge))) {
                return false;
            }
            if (!(bool) (int) $ffi->NETSCAPE_SPKI_set_pubkey($spki, $pkey)) {
                return false;
            }
            if (!(bool) (int) $ffi->NETSCAPE_SPKI_sign($spki, $pkey, $md)) {
                return false;
            }

            $b64 = $ffi->NETSCAPE_SPKI_b64_encode($spki);
            if (null === $b64) {
                return false;
            }

            return 'SPKAC='.\FFI::string($b64);
        } finally {
            $ffi->NETSCAPE_SPKI_free($spki);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function spkiVerify(string $spkac): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $cleaned = self::spkiCleanup($spkac);
        if ('' === $cleaned) {
            return false;
        }

        $spki = $ffi->NETSCAPE_SPKI_b64_decode($cleaned, \strlen($cleaned));
        if (null === $spki) {
            return false;
        }

        $pub = $ffi->X509_PUBKEY_get($spki->spkac->pubkey);
        if (null === $pub) {
            $ffi->NETSCAPE_SPKI_free($spki);

            return false;
        }

        try {
            return (int) $ffi->NETSCAPE_SPKI_verify($spki, $pub) > 0;
        } finally {
            $ffi->NETSCAPE_SPKI_free($spki);
            $ffi->EVP_PKEY_free($pub);
        }
    }

    /**
     * php-src php_openssl_spki_cleanup — strip CR/LF before base64 decode.
     */
    public static function spkiCleanup(string $src): string
    {
        return \str_replace(["\n", "\r"], '', $src);
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
typedef struct asn1_string_st ASN1_STRING;
typedef struct x509_pubkey_st X509_PUBKEY;
typedef struct NETSCAPE_SPKAC_st NETSCAPE_SPKAC;
typedef struct NETSCAPE_SPKI_st NETSCAPE_SPKI;

struct NETSCAPE_SPKAC_st {
    X509_PUBKEY *pubkey;
    ASN1_STRING *challenge;
};

struct NETSCAPE_SPKI_st {
    NETSCAPE_SPKAC *spkac;
    void *sig_algor;
    void *signature;
};

BIO *BIO_new_mem_buf(const void *buf, int len);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
void EVP_PKEY_free(EVP_PKEY *pkey);
const EVP_MD *EVP_get_digestbyname(const char *name);
NETSCAPE_SPKI *NETSCAPE_SPKI_new(void);
void NETSCAPE_SPKI_free(NETSCAPE_SPKI *a);
int ASN1_STRING_set(ASN1_STRING *x, const void *d, int len);
int NETSCAPE_SPKI_set_pubkey(NETSCAPE_SPKI *x, EVP_PKEY *pkey);
int NETSCAPE_SPKI_sign(NETSCAPE_SPKI *x, EVP_PKEY *pkey, const EVP_MD *md);
char *NETSCAPE_SPKI_b64_encode(NETSCAPE_SPKI *a);
NETSCAPE_SPKI *NETSCAPE_SPKI_b64_decode(const char *str, int len);
int NETSCAPE_SPKI_verify(NETSCAPE_SPKI *a, EVP_PKEY *r);
EVP_PKEY *X509_PUBKEY_get(X509_PUBKEY *key);
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
