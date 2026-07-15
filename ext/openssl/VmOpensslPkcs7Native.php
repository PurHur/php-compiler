<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * PKCS#7 / S/MIME via libcrypto FFI (php-src ext/openssl/openssl.c; #6804).
 */
final class VmOpensslPkcs7Native
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param list<array{0: ?string, 1: string}> $headers keyed (name, value) or indexed (null, line)
     */
    public static function sign(
        string $inputFilename,
        string $outputFilename,
        string $certPem,
        string $keyPem,
        array $headers,
        int $flags
    ): bool {
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

        $infile = $ffi->BIO_new_file($inputFilename, 'rb');
        $outfile = $ffi->BIO_new_file($outputFilename, 'wb');
        if (null === $infile || null === $outfile) {
            if (null !== $infile) {
                $ffi->BIO_free($infile);
            }
            if (null !== $outfile) {
                $ffi->BIO_free($outfile);
            }
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        $p7 = null;
        try {
            $p7 = $ffi->PKCS7_sign($cert, $pkey, null, $infile, $flags);
            if (null === $p7) {
                return false;
            }
            $ffi->BIO_ctrl($infile, 1 /* BIO_CTRL_RESET */, 0, null);
            if (!self::writeHeaders($ffi, $outfile, $headers)) {
                return false;
            }
            if (1 !== (int) $ffi->SMIME_write_PKCS7($outfile, $p7, $infile, $flags)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $p7) {
                $ffi->PKCS7_free($p7);
            }
            $ffi->BIO_free($infile);
            $ffi->BIO_free($outfile);
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @return bool|int true on success, false on verify failure, -1 on hard error (php-src)
     */
    public static function verify(
        string $inputFilename,
        int $flags,
        ?string $signersCertificatesFilename,
        ?string $contentOutputFilename
    ): bool|int {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        // php-src openssl_pkcs7_verify — strip DETACHED before PKCS7_verify().
        $flags &= ~OpensslConstants::PKCS7_DETACHED;

        $infile = $ffi->BIO_new_file($inputFilename, 'rb');
        if (null === $infile) {
            return -1;
        }

        $datainHolder = $ffi->new('BIO *[1]');
        $datainHolder[0] = null;
        $p7 = null;
        $store = null;
        $dataout = null;
        try {
            $p7 = $ffi->SMIME_read_PKCS7($infile, $datainHolder);
            if (null === $p7) {
                return -1;
            }
            $datain = $datainHolder[0];

            $store = $ffi->X509_STORE_new();
            if (null === $store) {
                return -1;
            }

            if (null !== $contentOutputFilename) {
                $dataout = $ffi->BIO_new_file($contentOutputFilename, 'wb');
                if (null === $dataout) {
                    return -1;
                }
            }

            if (1 !== (int) $ffi->PKCS7_verify($p7, null, $store, $datain, $dataout, $flags)) {
                return false;
            }

            if (null !== $signersCertificatesFilename) {
                $certout = $ffi->BIO_new_file($signersCertificatesFilename, 'wb');
                if (null === $certout) {
                    return -1;
                }
                try {
                    $signers = $ffi->PKCS7_get0_signers($p7, null, $flags);
                    if (null === $signers) {
                        return -1;
                    }
                    $count = (int) $ffi->OPENSSL_sk_num($signers);
                    for ($i = 0; $i < $count; ++$i) {
                        $signer = $ffi->OPENSSL_sk_value($signers, $i);
                        if (null === $signer) {
                            return -1;
                        }
                        if (1 !== (int) $ffi->PEM_write_bio_X509($certout, $signer)) {
                            return -1;
                        }
                    }
                    $ffi->OPENSSL_sk_free($signers);
                } finally {
                    $ffi->BIO_free($certout);
                }
            }

            return true;
        } finally {
            if (null !== $p7) {
                $ffi->PKCS7_free($p7);
            }
            if (null !== $store) {
                $ffi->X509_STORE_free($store);
            }
            if (null !== $datainHolder[0]) {
                $ffi->BIO_free($datainHolder[0]);
            }
            $ffi->BIO_free($infile);
            if (null !== $dataout) {
                $ffi->BIO_free($dataout);
            }
        }
    }

    /**
     * @param list<string>                            $recipientCertPems
     * @param list<array{0: ?string, 1: string}> $headers
     */
    public static function encrypt(
        string $inputFilename,
        string $outputFilename,
        array $recipientCertPems,
        array $headers,
        int $flags,
        int $cipherId
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $cipher = self::cipherFromId($ffi, $cipherId);
        if (null === $cipher) {
            return false;
        }

        $stack = $ffi->OPENSSL_sk_new_null();
        if (null === $stack) {
            return false;
        }
        $ownedCerts = [];
        $infile = null;
        $outfile = null;
        $p7 = null;
        try {
            foreach ($recipientCertPems as $pem) {
                $cert = self::readX509($ffi, $pem);
                if (null === $cert) {
                    return false;
                }
                $ownedCerts[] = $cert;
                if (1 !== (int) $ffi->OPENSSL_sk_push($stack, $cert)) {
                    return false;
                }
            }

            $infile = $ffi->BIO_new_file($inputFilename, 'rb');
            $outfile = $ffi->BIO_new_file($outputFilename, 'wb');
            if (null === $infile || null === $outfile) {
                return false;
            }

            $p7 = $ffi->PKCS7_encrypt($stack, $infile, $cipher, $flags);
            if (null === $p7) {
                return false;
            }
            if (!self::writeHeaders($ffi, $outfile, $headers)) {
                return false;
            }
            if (1 !== (int) $ffi->SMIME_write_PKCS7($outfile, $p7, null, $flags)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $p7) {
                $ffi->PKCS7_free($p7);
            }
            if (null !== $infile) {
                $ffi->BIO_free($infile);
            }
            if (null !== $outfile) {
                $ffi->BIO_free($outfile);
            }
            foreach ($ownedCerts as $cert) {
                $ffi->X509_free($cert);
            }
            $ffi->OPENSSL_sk_free($stack);
        }
    }

    public static function decrypt(
        string $inputFilename,
        string $outputFilename,
        string $certPem,
        string $keyPem
    ): bool {
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

        $infile = $ffi->BIO_new_file($inputFilename, 'rb');
        $outfile = $ffi->BIO_new_file($outputFilename, 'wb');
        if (null === $infile || null === $outfile) {
            if (null !== $infile) {
                $ffi->BIO_free($infile);
            }
            if (null !== $outfile) {
                $ffi->BIO_free($outfile);
            }
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        $datainHolder = $ffi->new('BIO *[1]');
        $datainHolder[0] = null;
        $p7 = null;
        try {
            $p7 = $ffi->SMIME_read_PKCS7($infile, $datainHolder);
            if (null === $p7) {
                return false;
            }
            if (1 !== (int) $ffi->PKCS7_decrypt($p7, $pkey, $cert, $outfile, 0)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $p7) {
                $ffi->PKCS7_free($p7);
            }
            if (null !== $datainHolder[0]) {
                $ffi->BIO_free($datainHolder[0]);
            }
            $ffi->BIO_free($infile);
            $ffi->BIO_free($outfile);
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @param \FFI                                    $ffi
     * @param \FFI\CData                              $outfile
     * @param list<array{0: ?string, 1: string}> $headers
     */
    private static function writeHeaders($ffi, $outfile, array $headers): bool
    {
        foreach ($headers as [$name, $value]) {
            $line = null === $name ? $value."\n" : $name.': '.$value."\n";
            $len = \strlen($line);
            if ($len !== (int) $ffi->BIO_write($outfile, $line, $len)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function cipherFromId($ffi, int $cipherId)
    {
        return match ($cipherId) {
            OpensslConstants::OPENSSL_CIPHER_RC2_40 => $ffi->EVP_rc2_40_cbc(),
            OpensslConstants::OPENSSL_CIPHER_RC2_128 => $ffi->EVP_rc2_cbc(),
            OpensslConstants::OPENSSL_CIPHER_RC2_64 => $ffi->EVP_rc2_64_cbc(),
            OpensslConstants::OPENSSL_CIPHER_DES => $ffi->EVP_des_cbc(),
            OpensslConstants::OPENSSL_CIPHER_3DES => $ffi->EVP_des_ede3_cbc(),
            OpensslConstants::OPENSSL_CIPHER_AES_128_CBC => $ffi->EVP_aes_128_cbc(),
            OpensslConstants::OPENSSL_CIPHER_AES_192_CBC => $ffi->EVP_aes_192_cbc(),
            OpensslConstants::OPENSSL_CIPHER_AES_256_CBC => $ffi->EVP_aes_256_cbc(),
            default => null,
        };
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
typedef struct x509_st X509;
typedef struct pkcs7_st PKCS7;
typedef struct x509_store_st X509_STORE;
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct openssl_stack_st OPENSSL_STACK;
BIO *BIO_new_file(const char *filename, const char *mode);
BIO *BIO_new_mem_buf(const void *buf, int len);
void BIO_free(BIO *a);
long BIO_ctrl(BIO *bp, int cmd, long larg, void *parg);
int BIO_write(BIO *b, const void *data, int dlen);

X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
void X509_free(X509 *a);
void EVP_PKEY_free(EVP_PKEY *pkey);

PKCS7 *PKCS7_sign(X509 *signcert, EVP_PKEY *pkey, OPENSSL_STACK *certs, BIO *data, int flags);
int SMIME_write_PKCS7(BIO *bio, PKCS7 *p7, BIO *data, int flags);
PKCS7 *SMIME_read_PKCS7(BIO *bio, BIO **bcont);
int PKCS7_verify(PKCS7 *p7, OPENSSL_STACK *certs, X509_STORE *store, BIO *indata, BIO *out, int flags);
OPENSSL_STACK *PKCS7_get0_signers(PKCS7 *p7, OPENSSL_STACK *certs, int flags);
PKCS7 *PKCS7_encrypt(OPENSSL_STACK *certs, BIO *in, const EVP_CIPHER *cipher, int flags);
int PKCS7_decrypt(PKCS7 *p7, EVP_PKEY *pkey, X509 *cert, BIO *data, int flags);
void PKCS7_free(PKCS7 *p7);

X509_STORE *X509_STORE_new(void);
void X509_STORE_free(X509_STORE *v);

OPENSSL_STACK *OPENSSL_sk_new_null(void);
int OPENSSL_sk_push(OPENSSL_STACK *sk, void *data);
int OPENSSL_sk_num(const OPENSSL_STACK *sk);
void *OPENSSL_sk_value(const OPENSSL_STACK *sk, int idx);
void OPENSSL_sk_free(OPENSSL_STACK *sk);

const EVP_CIPHER *EVP_rc2_40_cbc(void);
const EVP_CIPHER *EVP_rc2_cbc(void);
const EVP_CIPHER *EVP_rc2_64_cbc(void);
const EVP_CIPHER *EVP_des_cbc(void);
const EVP_CIPHER *EVP_des_ede3_cbc(void);
const EVP_CIPHER *EVP_aes_128_cbc(void);
const EVP_CIPHER *EVP_aes_192_cbc(void);
const EVP_CIPHER *EVP_aes_256_cbc(void);
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
