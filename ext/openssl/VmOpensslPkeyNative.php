<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * EVP_PKEY keygen / PEM import-export via libcrypto FFI (php-src ext/openssl/xp.c; #6295).
 */
final class VmOpensslPkeyNative
{
    private const EVP_PKEY_RSA = 6;
    private const EVP_PKEY_RSA2 = 19;
    private const EVP_PKEY_DSA = 116;
    private const EVP_PKEY_DSA1 = 67;
    private const EVP_PKEY_DSA2 = 66;
    private const EVP_PKEY_DSA3 = 113;
    private const EVP_PKEY_DSA4 = 70;
    private const EVP_PKEY_DH = 28;
    private const EVP_PKEY_EC = 408;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function generateRsa(int $bits): string|false
    {
        if ($bits < 384) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new_id(self::EVP_PKEY_RSA, null);
        if (null === $ctx) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_keygen_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_keygen_bits($ctx, $bits)) {
                return false;
            }

            $pkeyOut = $ffi->new('EVP_PKEY *[1]');
            if (1 !== (int) $ffi->EVP_PKEY_keygen($ctx, $pkeyOut)) {
                return false;
            }
            if (null === $pkeyOut[0]) {
                return false;
            }

            try {
                return self::writePrivateKeyPem($ffi, $pkeyOut[0], null);
            } finally {
                $ffi->EVP_PKEY_free($pkeyOut[0]);
            }
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
        }
    }

    public static function normalizePrivateKeyPem(string $pem, ?string $passphrase = null): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $pem, $passphrase);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePrivateKeyPem($ffi, $pkey, $passphrase);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function exportPrivateKeyPem(string $pem, ?string $passphrase = null): string|false
    {
        return self::normalizePrivateKeyPem($pem, $passphrase);
    }

    public static function exportPublicKeyPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePublicKeyPem($ffi, $pkey);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * Normalize a public-key PEM only (php-src openssl_pkey_get_public; #20240).
     * Private-key PEMs must fail — do not coerce via readAnyKey().
     */
    public static function normalizePublicKeyPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPublicKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePublicKeyPem($ffi, $pkey);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * openssl_pkey_get_details() array (php-src ext/openssl/openssl.c; #20240).
     *
     * @return array{bits: int, key: string, type: int, rsa?: array<string, string>}|false
     */
    public static function getDetails(string $pem): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            $bits = (int) $ffi->EVP_PKEY_get_bits($pkey);
            if ($bits <= 0) {
                return false;
            }
            $pubPem = self::writePublicKeyPem($ffi, $pkey);
            if (false === $pubPem) {
                return false;
            }

            $baseId = (int) $ffi->EVP_PKEY_get_base_id($pkey);
            $type = match ($baseId) {
                self::EVP_PKEY_RSA, self::EVP_PKEY_RSA2 => OpensslConstants::OPENSSL_KEYTYPE_RSA,
                self::EVP_PKEY_DSA, self::EVP_PKEY_DSA1, self::EVP_PKEY_DSA2, self::EVP_PKEY_DSA3, self::EVP_PKEY_DSA4 => OpensslConstants::OPENSSL_KEYTYPE_DSA,
                self::EVP_PKEY_DH => OpensslConstants::OPENSSL_KEYTYPE_DH,
                self::EVP_PKEY_EC => OpensslConstants::OPENSSL_KEYTYPE_EC,
                default => -1,
            };

            $details = [
                'bits' => $bits,
                'key' => $pubPem,
                'type' => $type,
            ];

            if (OpensslConstants::OPENSSL_KEYTYPE_RSA === $type) {
                $rsaDetails = self::rsaDetails($ffi, $pkey);
                if (null !== $rsaDetails) {
                    $details['rsa'] = $rsaDetails;
                }
            }

            return $details;
        } finally {
            $ffi->EVP_PKEY_free($pkey);
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
            return $ffi->PEM_read_bio_PUBKEY($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readAnyKey($ffi, string $pem)
    {
        $pub = self::readPublicKey($ffi, $pem);
        if (null !== $pub) {
            return $pub;
        }

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
     * @param \FFI\CData $pkey
     *
     * @return array<string, string>|null
     */
    private static function rsaDetails($ffi, $pkey): ?array
    {
        $rsa = $ffi->EVP_PKEY_get0_RSA($pkey);
        if (null === $rsa) {
            return null;
        }

        $details = [];
        foreach ([
            'n' => 'RSA_get0_n',
            'e' => 'RSA_get0_e',
            'd' => 'RSA_get0_d',
            'p' => 'RSA_get0_p',
            'q' => 'RSA_get0_q',
            'dmp1' => 'RSA_get0_dmp1',
            'dmq1' => 'RSA_get0_dmq1',
            'iqmp' => 'RSA_get0_iqmp',
        ] as $key => $getter) {
            $bn = $ffi->$getter($rsa);
            if (null === $bn) {
                continue;
            }
            $bin = self::bn2bin($ffi, $bn);
            if (null === $bin) {
                continue;
            }
            $details[$key] = $bin;
        }

        return [] === $details ? null : $details;
    }

    /**
     * @param \FFI $ffi
     * @param \FFI\CData $bn
     */
    private static function bn2bin($ffi, $bn): ?string
    {
        $bits = (int) $ffi->BN_num_bits($bn);
        if ($bits <= 0) {
            return '';
        }
        $len = intdiv($bits + 7, 8);
        if ($len <= 0) {
            return '';
        }
        $buf = $ffi->new("unsigned char[{$len}]");
        $written = (int) $ffi->BN_bn2bin($bn, $buf);
        if ($written <= 0) {
            return null;
        }

        return \FFI::string($buf, $written);
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $pkey
     */
    private static function writePublicKeyPem($ffi, $pkey): string|false
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-pubkey-');
        if (false === $tmp) {
            return false;
        }

        $bio = $ffi->BIO_new_file($tmp, 'wb');
        if (null === $bio) {
            @\unlink($tmp);

            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_PUBKEY($bio, $pkey)) {
                return false;
            }
        } finally {
            $ffi->BIO_free($bio);
        }

        $pem = VmFsReadNative::read($tmp);
        @\unlink($tmp);
        if (false === $pem || '' === $pem) {
            return false;
        }

        return $pem;
    }

    public static function encrypt(string $data, string $publicKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_encrypt_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function decrypt(string $data, string $privateKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $privateKeyPem, null);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_decrypt_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function privateEncrypt(string $data, string $privateKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $privateKeyPem, null);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_sign_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_sign($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_sign($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function publicDecrypt(string $data, string $publicKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPrivateKey($ffi, string $pem, ?string $passphrase)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            if (null !== $passphrase && '' !== $passphrase) {
                return $ffi->PEM_read_bio_PrivateKey($bio, null, null, $passphrase);
            }

            return $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $pkey
     */
    private static function writePrivateKeyPem($ffi, $pkey, ?string $passphrase): string|false
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-pkey-');
        if (false === $tmp) {
            return false;
        }

        $bio = $ffi->BIO_new_file($tmp, 'wb');
        if (null === $bio) {
            @\unlink($tmp);

            return false;
        }

        try {
            // php-src openssl_pkey_export(_to_file): encrypt with 3DES when passphrase set
            // (priv_key_encrypt default true; ext/openssl/openssl.c PEM_write_bio_PrivateKey).
            $cipher = null;
            $kstr = null;
            $klen = 0;
            if (null !== $passphrase && '' !== $passphrase) {
                $cipher = $ffi->EVP_des_ede3_cbc();
                $klen = \strlen($passphrase);
                $kstr = $ffi->new('unsigned char['.$klen.']');
                \FFI::memcpy($kstr, $passphrase, $klen);
            }
            if (1 !== (int) $ffi->PEM_write_bio_PrivateKey($bio, $pkey, $cipher, $kstr, $klen, null, null)) {
                return false;
            }
        } finally {
            $ffi->BIO_free($bio);
        }

        $pem = VmFsReadNative::read($tmp);
        @\unlink($tmp);
        if (false === $pem || '' === $pem) {
            return false;
        }

        return $pem;
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
typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct rsa_st RSA;
typedef struct bignum_st BIGNUM;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new_file(const char *filename, const char *mode);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
const EVP_CIPHER *EVP_des_ede3_cbc(void);
void EVP_PKEY_free(EVP_PKEY *pkey);
EVP_PKEY_CTX *EVP_PKEY_CTX_new_id(int id, void *e);
EVP_PKEY_CTX *EVP_PKEY_CTX_new(EVP_PKEY *pkey, void *e);
void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
int EVP_PKEY_keygen_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_CTX_set_rsa_keygen_bits(EVP_PKEY_CTX *ctx, int bits);
int EVP_PKEY_keygen(EVP_PKEY_CTX *ctx, EVP_PKEY **ppkey);
int EVP_PKEY_encrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_encrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen,
    const unsigned char *in, size_t inlen);
int EVP_PKEY_decrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_decrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen,
    const unsigned char *in, size_t inlen);
int EVP_PKEY_sign_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_sign(EVP_PKEY_CTX *ctx, unsigned char *sig, size_t *siglen,
    const unsigned char *tbs, size_t tbslen);
int EVP_PKEY_verify_recover_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_verify_recover(EVP_PKEY_CTX *ctx, unsigned char *rout, size_t *routlen,
    const unsigned char *sig, size_t siglen);
int EVP_PKEY_CTX_set_rsa_padding(EVP_PKEY_CTX *ctx, int pad);
int EVP_PKEY_get_bits(const EVP_PKEY *pkey);
int EVP_PKEY_get_base_id(const EVP_PKEY *pkey);
RSA *EVP_PKEY_get0_RSA(EVP_PKEY *pkey);
BIGNUM *RSA_get0_n(const RSA *d);
BIGNUM *RSA_get0_e(const RSA *d);
BIGNUM *RSA_get0_d(const RSA *d);
BIGNUM *RSA_get0_p(const RSA *d);
BIGNUM *RSA_get0_q(const RSA *d);
BIGNUM *RSA_get0_dmp1(const RSA *d);
BIGNUM *RSA_get0_dmq1(const RSA *d);
BIGNUM *RSA_get0_iqmp(const RSA *d);
int BN_num_bits(const BIGNUM *a);
int BN_bn2bin(const BIGNUM *a, unsigned char *to);
int PEM_write_bio_PrivateKey(BIO *bp, EVP_PKEY *x, const EVP_CIPHER *enc,
    const unsigned char *kstr, int klen, void *cb, void *u);
int PEM_write_bio_PUBKEY(BIO *bp, EVP_PKEY *x);
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
