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

    /**
     * openssl_pkcs7_read() — extract cert/CRL PEMs from PKCS#7 PEM *content*
     * (php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_read); #20305).
     *
     * @return list<string>|false
     */
    public static function read(string $pkcs7PemContent): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $bio = $ffi->BIO_new_mem_buf($pkcs7PemContent, \strlen($pkcs7PemContent));
        if (null === $bio) {
            return false;
        }

        $p7 = null;
        try {
            $p7 = $ffi->PEM_read_bio_PKCS7($bio, null, null, null);
            if (null === $p7) {
                return false;
            }

            // Layout mirrors openssl/pkcs7.h through the `d` union (OpenSSL 1.1/3).
            $layout = $ffi->cast('PKCS7_LAYOUT*', $p7);
            $nid = (int) $ffi->OBJ_obj2nid($layout->type);
            $certStack = null;
            $crlStack = null;
            // NID_pkcs7_signed = 22; NID_pkcs7_signedAndEnveloped = 24 (obj_mac.h).
            if (22 === $nid && null !== $layout->d->sign) {
                $certStack = $layout->d->sign->cert;
                $crlStack = $layout->d->sign->crl;
            } elseif (24 === $nid && null !== $layout->d->signed_and_enveloped) {
                $certStack = $layout->d->signed_and_enveloped->cert;
                $crlStack = $layout->d->signed_and_enveloped->crl;
            }

            // php-src always array-inits then RETVAL_TRUE even when stacks are empty.
            $out = [];
            if (null !== $certStack) {
                $count = (int) $ffi->OPENSSL_sk_num($certStack);
                for ($i = 0; $i < $count; ++$i) {
                    $x509 = $ffi->OPENSSL_sk_value($certStack, $i);
                    if (null === $x509) {
                        continue;
                    }
                    $pem = self::x509ToPem($ffi, $x509);
                    if (false === $pem) {
                        continue;
                    }
                    $out[$i] = $pem;
                }
            }
            if (null !== $crlStack) {
                // php-src reuses the same indices for CRLs (overwrites cert slots).
                $count = (int) $ffi->OPENSSL_sk_num($crlStack);
                for ($i = 0; $i < $count; ++$i) {
                    $crl = $ffi->OPENSSL_sk_value($crlStack, $i);
                    if (null === $crl) {
                        continue;
                    }
                    $pem = self::x509CrlToPem($ffi, $crl);
                    if (false === $pem) {
                        continue;
                    }
                    $out[$i] = $pem;
                }
            }

            return array_values($out);
        } finally {
            if (null !== $p7) {
                $ffi->PKCS7_free($p7);
            }
            $ffi->BIO_free($bio);
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
     * @param \FFI       $ffi
     * @param \FFI\CData $x509
     *
     * @return string|false
     */
    private static function x509ToPem($ffi, $x509): string|false
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
            $read = (int) $ffi->BIO_read($outBio, $buf, $pending);

            return $read > 0 ? \FFI::string($buf, $read) : false;
        } finally {
            $ffi->BIO_free($outBio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $crl
     *
     * @return string|false
     */
    private static function x509CrlToPem($ffi, $crl): string|false
    {
        $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $outBio) {
            return false;
        }
        try {
            if (1 !== (int) $ffi->PEM_write_bio_X509_CRL($outBio, $crl)) {
                return false;
            }
            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }
            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($outBio, $buf, $pending);

            return $read > 0 ? \FFI::string($buf, $read) : false;
        } finally {
            $ffi->BIO_free($outBio);
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
typedef struct bio_method_st BIO_METHOD;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct x509_st X509;
typedef struct x509_crl_st X509_CRL;
typedef struct asn1_object_st ASN1_OBJECT;
typedef struct pkcs7_st PKCS7;
typedef struct x509_store_st X509_STORE;
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct openssl_stack_st OPENSSL_STACK;

typedef struct {
    void *version;
    void *md_algs;
    OPENSSL_STACK *cert;
    OPENSSL_STACK *crl;
    void *signer_info;
    void *contents;
} PKCS7_SIGNED_LAYOUT;

typedef struct {
    void *version;
    void *md_algs;
    OPENSSL_STACK *cert;
    OPENSSL_STACK *crl;
    void *signer_info;
    void *enc_data;
    void *recipientinfo;
} PKCS7_SIGN_ENVELOPE_LAYOUT;

typedef struct {
    unsigned char *asn1;
    long length;
    int state;
    int detached;
    ASN1_OBJECT *type;
    union {
        char *ptr;
        void *data;
        PKCS7_SIGNED_LAYOUT *sign;
        void *enveloped;
        PKCS7_SIGN_ENVELOPE_LAYOUT *signed_and_enveloped;
        void *digest;
        void *encrypted;
        void *other;
    } d;
} PKCS7_LAYOUT;

BIO *BIO_new_file(const char *filename, const char *mode);
BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new(const BIO_METHOD *type);
const BIO_METHOD *BIO_s_mem(void);
void BIO_free(BIO *a);
long BIO_ctrl(BIO *bp, int cmd, long larg, void *parg);
size_t BIO_ctrl_pending(BIO *b);
int BIO_write(BIO *b, const void *data, int dlen);
int BIO_read(BIO *b, void *data, int dlen);

X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
int PEM_write_bio_X509_CRL(BIO *bp, const X509_CRL *crl);
void X509_free(X509 *a);
void EVP_PKEY_free(EVP_PKEY *pkey);
int OBJ_obj2nid(const ASN1_OBJECT *o);

PKCS7 *PEM_read_bio_PKCS7(BIO *bp, PKCS7 **x, void *cb, void *u);
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
