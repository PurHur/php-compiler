<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * X509 PEM validate/normalize via libcrypto FFI — no host ext/openssl delegation (#8306, #7268).
 *
 * php-src: ext/openssl/xp.c — openssl_x509_read()
 */
final class VmOpensslX509Native
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * Parse PEM and return normalized certificate PEM, or false when invalid/unavailable.
     */
    public static function normalizeCertificatePem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inBio = null;
        $outBio = null;
        $x509 = null;

        try {
            $inBio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
            if (null === $inBio) {
                return false;
            }

            $x509 = $ffi->PEM_read_bio_X509($inBio, null, null, null);
            if (null === $x509) {
                return false;
            }

            $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
            if (null === $outBio) {
                return false;
            }

            if (1 !== (int) $ffi->PEM_write_bio_X509($outBio, $x509)) {
                return false;
            }

            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }

            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($outBio, $buf, $pending);
            if ($read <= 0) {
                return false;
            }

            return \FFI::string($buf, $read);
        } finally {
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $inBio) {
                $ffi->BIO_free($inBio);
            }
            if (null !== $outBio) {
                $ffi->BIO_free($outBio);
            }
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
typedef struct x509_st X509;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new(const BIO_METHOD *type);
const BIO_METHOD *BIO_s_mem(void);
void BIO_free(BIO *a);
X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
size_t BIO_ctrl_pending(BIO *b);
int BIO_read(BIO *b, void *data, int dlen);
void X509_free(X509 *a);
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
