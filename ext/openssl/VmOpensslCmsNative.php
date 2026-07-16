<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * CMS / S/MIME via libcrypto FFI (php-src ext/openssl/openssl.c; #6592).
 */
final class VmOpensslCmsNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param list<array{0: ?string, 1: string}> $headers
     */
    public static function sign(
        string $inputFilename,
        string $outputFilename,
        string $certPem,
        string $keyPem,
        array $headers,
        int $flags,
        int $encoding
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        if (OpensslConstants::OPENSSL_ENCODING_SMIME === $encoding
            && 0 !== ($flags & OpensslConstants::OPENSSL_CMS_DETACHED)
        ) {
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

        $cms = null;
        try {
            $cms = $ffi->CMS_sign($cert, $pkey, null, $infile, $flags);
            if (null === $cms) {
                return false;
            }
            $ffi->BIO_ctrl($infile, 1 /* BIO_CTRL_RESET */, 0, null);
            if (OpensslConstants::OPENSSL_ENCODING_SMIME === $encoding
                && !self::writeHeaders($ffi, $outfile, $headers)
            ) {
                return false;
            }
            if (!self::writeCms($ffi, $outfile, $cms, $infile, $flags, $encoding)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $cms) {
                $ffi->CMS_ContentInfo_free($cms);
            }
            $ffi->BIO_free($infile);
            $ffi->BIO_free($outfile);
            $ffi->X509_free($cert);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function verify(
        string $inputFilename,
        int $flags,
        ?string $signersCertificatesFilename,
        ?string $contentOutputFilename,
        int $encoding
    ): bool {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $infile = $ffi->BIO_new_file($inputFilename, 'rb');
        if (null === $infile) {
            return false;
        }

        $datainHolder = $ffi->new('BIO *[1]');
        $datainHolder[0] = null;
        $cms = null;
        $store = null;
        $dataout = null;
        try {
            $cms = self::readCms($ffi, $infile, $datainHolder, $encoding);
            if (null === $cms) {
                return false;
            }
            $datain = $datainHolder[0];
            if (OpensslConstants::OPENSSL_ENCODING_SMIME !== $encoding
                && 0 === ($flags & OpensslConstants::OPENSSL_CMS_DETACHED)
            ) {
                $datain = null;
            }

            $store = $ffi->X509_STORE_new();
            if (null === $store) {
                return false;
            }

            if (null !== $contentOutputFilename) {
                $dataout = $ffi->BIO_new_file($contentOutputFilename, 'wb');
                if (null === $dataout) {
                    return false;
                }
            }

            if (1 !== (int) $ffi->CMS_verify($cms, null, $store, $datain, $dataout, $flags)) {
                return false;
            }

            if (null !== $signersCertificatesFilename) {
                $certout = $ffi->BIO_new_file($signersCertificatesFilename, 'wb');
                if (null === $certout) {
                    return false;
                }
                try {
                    $signers = $ffi->CMS_get0_signers($cms);
                    if (null === $signers) {
                        return false;
                    }
                    $count = (int) $ffi->OPENSSL_sk_num($signers);
                    for ($i = 0; $i < $count; ++$i) {
                        $signer = $ffi->OPENSSL_sk_value($signers, $i);
                        if (null === $signer) {
                            return false;
                        }
                        if (1 !== (int) $ffi->PEM_write_bio_X509($certout, $signer)) {
                            return false;
                        }
                    }
                } finally {
                    $ffi->BIO_free($certout);
                }
            }

            return true;
        } finally {
            if (null !== $cms) {
                $ffi->CMS_ContentInfo_free($cms);
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
        int $encoding,
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
        $cms = null;
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

            $cms = $ffi->CMS_encrypt($stack, $infile, $cipher, $flags);
            if (null === $cms) {
                return false;
            }
            $ffi->BIO_ctrl($infile, 1 /* BIO_CTRL_RESET */, 0, null);
            if (OpensslConstants::OPENSSL_ENCODING_SMIME === $encoding
                && !self::writeHeaders($ffi, $outfile, $headers)
            ) {
                return false;
            }
            if (!self::writeCms($ffi, $outfile, $cms, $infile, $flags, $encoding)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $cms) {
                $ffi->CMS_ContentInfo_free($cms);
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
        string $keyPem,
        int $encoding
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
        $cms = null;
        try {
            $cms = self::readCms($ffi, $infile, $datainHolder, $encoding);
            if (null === $cms) {
                return false;
            }
            if (1 !== (int) $ffi->CMS_decrypt($cms, $pkey, $cert, null, $outfile, 0)) {
                return false;
            }

            return true;
        } finally {
            if (null !== $cms) {
                $ffi->CMS_ContentInfo_free($cms);
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
     * php-src openssl_cms_read() — first arg is CMS PEM *content* (not a path).
     *
     * @return list<string>|false
     */
    public static function read(string $cmsPemContent): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $bio = $ffi->BIO_new_mem_buf($cmsPemContent, \strlen($cmsPemContent));
        if (null === $bio) {
            return false;
        }

        $cms = null;
        $certs = null;
        try {
            $cms = $ffi->PEM_read_bio_CMS($bio, null, null, null);
            if (null === $cms) {
                return false;
            }
            $certs = $ffi->CMS_get1_certs($cms);
            $out = [];
            if (null !== $certs) {
                $count = (int) $ffi->OPENSSL_sk_num($certs);
                for ($i = 0; $i < $count; ++$i) {
                    $x509 = $ffi->OPENSSL_sk_value($certs, $i);
                    if (null === $x509) {
                        return false;
                    }
                    $pem = self::x509ToPem($ffi, $x509);
                    if (false === $pem) {
                        return false;
                    }
                    $out[] = $pem;
                }
            }

            return $out;
        } finally {
            if (null !== $certs) {
                // CMS_get1_certs returns an owned stack — free certs with X509_free.
                $count = (int) $ffi->OPENSSL_sk_num($certs);
                for ($i = 0; $i < $count; ++$i) {
                    $x509 = $ffi->OPENSSL_sk_value($certs, $i);
                    if (null !== $x509) {
                        $ffi->X509_free($x509);
                    }
                }
                $ffi->OPENSSL_sk_free($certs);
            }
            if (null !== $cms) {
                $ffi->CMS_ContentInfo_free($cms);
            }
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $outfile
     * @param \FFI\CData $cms
     * @param \FFI\CData $infile
     */
    private static function writeCms($ffi, $outfile, $cms, $infile, int $flags, int $encoding): bool
    {
        return match ($encoding) {
            OpensslConstants::OPENSSL_ENCODING_SMIME => 1 === (int) $ffi->SMIME_write_CMS($outfile, $cms, $infile, $flags),
            OpensslConstants::OPENSSL_ENCODING_DER => 1 === (int) $ffi->i2d_CMS_bio($outfile, $cms),
            OpensslConstants::OPENSSL_ENCODING_PEM => 1 === (int) $ffi->PEM_write_bio_CMS($outfile, $cms),
            default => false,
        };
    }

    /**
     * @param \FFI            $ffi
     * @param \FFI\CData      $bio
     * @param \FFI\CData      $datainHolder BIO*[1]
     *
     * @return \FFI\CData|null
     */
    private static function readCms($ffi, $bio, $datainHolder, int $encoding)
    {
        return match ($encoding) {
            OpensslConstants::OPENSSL_ENCODING_PEM => $ffi->PEM_read_bio_CMS($bio, null, null, null),
            OpensslConstants::OPENSSL_ENCODING_DER => $ffi->d2i_CMS_bio($bio, null),
            OpensslConstants::OPENSSL_ENCODING_SMIME => $ffi->SMIME_read_CMS($bio, $datainHolder),
            default => null,
        };
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
typedef struct x509_store_st X509_STORE;
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct openssl_stack_st OPENSSL_STACK;
typedef struct CMS_ContentInfo_st CMS_ContentInfo;

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
void X509_free(X509 *a);
void EVP_PKEY_free(EVP_PKEY *pkey);

CMS_ContentInfo *CMS_sign(X509 *signcert, EVP_PKEY *pkey, OPENSSL_STACK *certs, BIO *data, unsigned int flags);
CMS_ContentInfo *CMS_encrypt(OPENSSL_STACK *certs, BIO *in, const EVP_CIPHER *cipher, unsigned int flags);
int CMS_verify(CMS_ContentInfo *cms, OPENSSL_STACK *certs, X509_STORE *store, BIO *dcont, BIO *out, unsigned int flags);
int CMS_decrypt(CMS_ContentInfo *cms, EVP_PKEY *pkey, X509 *cert, BIO *dcont, BIO *out, unsigned int flags);
void CMS_ContentInfo_free(CMS_ContentInfo *cms);
OPENSSL_STACK *CMS_get0_signers(CMS_ContentInfo *cms);
OPENSSL_STACK *CMS_get1_certs(CMS_ContentInfo *cms);

int SMIME_write_CMS(BIO *bio, CMS_ContentInfo *cms, BIO *data, int flags);
CMS_ContentInfo *SMIME_read_CMS(BIO *bio, BIO **bcont);
CMS_ContentInfo *PEM_read_bio_CMS(BIO *bp, CMS_ContentInfo **x, void *cb, void *u);
int PEM_write_bio_CMS(BIO *bp, const CMS_ContentInfo *x);
CMS_ContentInfo *d2i_CMS_bio(BIO *bp, CMS_ContentInfo **cms);
int i2d_CMS_bio(BIO *bp, CMS_ContentInfo *cms);

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
