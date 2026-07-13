<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * PKCS#12 keystore read/write via libcrypto FFI (php-src ext/openssl/pkcs12.c; #6420).
 */
final class VmOpensslPkcs12Native
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{cert: string, pkey: string}|false
     */
    public static function parsePkcs12(string $blob, string $passphrase): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $bio = $ffi->BIO_new_mem_buf($blob, \strlen($blob));
        if (null === $bio) {
            return false;
        }

        $p12Out = $ffi->new('PKCS12 *[1]');
        $p12 = $ffi->d2i_PKCS12_bio($bio, $p12Out);
        $ffi->BIO_free($bio);
        if (null === $p12) {
            $p12 = $p12Out[0];
        }
        if (null === $p12) {
            return false;
        }

        $pkeyOut = $ffi->new('EVP_PKEY *[1]');
        $certOut = $ffi->new('X509 *[1]');
        $caOut = $ffi->new('STACK_OF_X509 *[1]');

        try {
            if (1 !== (int) $ffi->PKCS12_parse($p12, $passphrase, $pkeyOut, $certOut, $caOut)) {
                return false;
            }
            if (null === $certOut[0] || null === $pkeyOut[0]) {
                return false;
            }

            $certPem = self::writeX509Pem($ffi, $certOut[0]);
            $keyPem = self::writePrivateKeyPem($ffi, $pkeyOut[0]);
            if (false === $certPem || false === $keyPem) {
                return false;
            }

            return ['cert' => $certPem, 'pkey' => $keyPem];
        } finally {
            if (null !== $pkeyOut[0]) {
                $ffi->EVP_PKEY_free($pkeyOut[0]);
            }
            if (null !== $certOut[0]) {
                $ffi->X509_free($certOut[0]);
            }
            if (null !== $caOut[0]) {
                $ffi->OPENSSL_sk_free($caOut[0]);
            }
            $ffi->PKCS12_free($p12);
        }
    }

    /**
     * @param list<string> $extraCertPems
     */
    public static function createPkcs12(
        string $certPem,
        string $keyPem,
        string $passphrase,
        string $friendlyName = '',
        array $extraCertPems = []
    ): string|false {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $cert = self::readX509($ffi, $certPem);
        $pkey = self::readPrivateKey($ffi, $keyPem);
        if (null === $cert || null === $pkey) {
            if (null !== $cert) {
                $ffi->X509_free($cert);
            }
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }

            return false;
        }

        $caStack = null;
        $extraCerts = [];
        try {
            if ([] !== $extraCertPems) {
                $caStack = $ffi->OPENSSL_sk_new_null();
                if (null === $caStack) {
                    return false;
                }
                foreach ($extraCertPems as $extraPem) {
                    $extra = self::readX509($ffi, $extraPem);
                    if (null === $extra) {
                        return false;
                    }
                    $extraCerts[] = $extra;
                    if (1 !== (int) $ffi->OPENSSL_sk_push($caStack, $extra)) {
                        return false;
                    }
                }
            }

            // php-src ext/openssl/openssl.c — zeros select OpenSSL 3 defaults (PBES2 + AES-256-CBC).
            $p12 = $ffi->PKCS12_create(
                $passphrase,
                '' !== $friendlyName ? $friendlyName : null,
                $pkey,
                $cert,
                $caStack,
                0,
                0,
                0,
                0,
                0
            );
            if (null === $p12) {
                return false;
            }

            try {
                $outPtr = $ffi->new('unsigned char *');
                $len = (int) $ffi->i2d_PKCS12($p12, \FFI::addr($outPtr));
                if ($len <= 0) {
                    return false;
                }

                return \FFI::string($outPtr, $len);
            } finally {
                $ffi->PKCS12_free($p12);
            }
        } finally {
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);
            foreach ($extraCerts as $extra) {
                $ffi->X509_free($extra);
            }
            if (null !== $caStack) {
                $ffi->OPENSSL_sk_free($caStack);
            }
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readX509($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_X509($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
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
            $pkey = $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
            if (null !== $pkey) {
                return $pkey;
            }

            return $ffi->PEM_read_bio_PUBKEY($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $x509
     */
    private static function writeX509Pem($ffi, $x509): string|false
    {
        $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $outBio) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_X509($outBio, $x509)) {
                return false;
            }
            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }
            $buf = $ffi->new("char[{$pending}]");
            if ((int) $ffi->BIO_read($outBio, $buf, $pending) <= 0) {
                return false;
            }

            return \FFI::string($buf, $pending);
        } finally {
            $ffi->BIO_free($outBio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $pkey
     */
    private static function writePrivateKeyPem($ffi, $pkey): string|false
    {
        $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $outBio) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_PrivateKey($outBio, $pkey, null, null, 0, null, null)) {
                return false;
            }
            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }
            $buf = $ffi->new("char[{$pending}]");
            if ((int) $ffi->BIO_read($outBio, $buf, $pending) <= 0) {
                return false;
            }

            return \FFI::string($buf, $pending);
        } finally {
            $ffi->BIO_free($outBio);
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
typedef struct bio_method_st BIO_METHOD;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct x509_st X509;
typedef struct pkcs12_st PKCS12;
typedef struct stack_st_X509 STACK_OF_X509;
typedef struct openssl_stack_st OPENSSL_STACK;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new(const BIO_METHOD *type);
const BIO_METHOD *BIO_s_mem(void);
void BIO_free(BIO *a);
size_t BIO_ctrl_pending(BIO *b);
int BIO_read(BIO *b, void *data, int dlen);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
void EVP_PKEY_free(EVP_PKEY *pkey);
void X509_free(X509 *a);
PKCS12 *d2i_PKCS12_bio(BIO *bp, PKCS12 **p12);
int i2d_PKCS12_bio(BIO *bp, PKCS12 *p12);
int i2d_PKCS12(PKCS12 *a, unsigned char **out);
void PKCS12_free(PKCS12 *a);
int PKCS12_parse(PKCS12 *p12, const char *pass, EVP_PKEY **pkey, X509 **cert, STACK_OF_X509 **ca);
PKCS12 *PKCS12_create(const char *pass, const char *name, EVP_PKEY *pkey, X509 *cert, STACK_OF_X509 *ca, int nid_key, int nid_cert, int iter, int mac_iter, int keytype);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
int PEM_write_bio_PrivateKey(BIO *bp, EVP_PKEY *x, void *enc, void *kstr, int klen, void *cb, void *u);
OPENSSL_STACK *OPENSSL_sk_new_null(void);
int OPENSSL_sk_push(OPENSSL_STACK *sk, void *data);
void OPENSSL_sk_free(OPENSSL_STACK *sk);
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
