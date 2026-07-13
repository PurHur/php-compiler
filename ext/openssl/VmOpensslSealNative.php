<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * Envelope seal/open via EVP_Encrypt + EVP_PKEY_encrypt (php-src ext/openssl/openssl.c; #6523).
 *
 * OpenSSL 3 does not export EVP_SealUpdate (macro); mirror EVP_Seal/Open semantics manually.
 */
final class VmOpensslSealNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param list<string> $publicKeyPems
     *
     * @return array{length: int, sealed: string, encrypted_keys: list<string>, iv: string}|false
     */
    public static function seal(string $data, array $publicKeyPems, string $cipherAlgo, bool $assignIv): array|false
    {
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
        if ($ivLen > 0 && !$assignIv) {
            return false;
        }

        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $keyLen || $keyLen <= 0) {
            return false;
        }

        $sessionKey = $ffi->new("unsigned char[{$keyLen}]");
        if (1 !== (int) $ffi->RAND_bytes($sessionKey, $keyLen)) {
            return false;
        }

        $iv = '';
        $ivBuf = null;
        if ($ivLen > 0) {
            $ivBuf = $ffi->new("unsigned char[{$ivLen}]");
            if (1 !== (int) $ffi->RAND_bytes($ivBuf, $ivLen)) {
                return false;
            }
            $iv = \FFI::string($ivBuf, $ivLen);
        }

        $dataLen = \strlen($data);
        $outBufLen = $dataLen + 32;
        $outBuf = $ffi->new("unsigned char[{$outBufLen}]");

        $ctx = $ffi->EVP_CIPHER_CTX_new();
        if (null === $ctx) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_EncryptInit_ex($ctx, $cipher, null, $sessionKey, $ivBuf)) {
                return false;
            }

            $len1 = $ffi->new('int');
            $len1->cdata = 0;
            $inBuf = $ffi->new("unsigned char[{$dataLen}]");
            \FFI::memcpy($inBuf, $data, $dataLen);
            if (1 !== (int) $ffi->EVP_EncryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                return false;
            }

            $len2 = $ffi->new('int');
            $len2->cdata = 0;
            $offset = (int) $len1->cdata;
            if (1 !== (int) $ffi->EVP_EncryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                return false;
            }

            $totalLen = $offset + (int) $len2->cdata;
            if ($totalLen <= 0) {
                return false;
            }
            $sealed = \FFI::string($outBuf, $totalLen);
        } finally {
            $ffi->EVP_CIPHER_CTX_free($ctx);
        }

        $encryptedKeys = [];
        foreach ($publicKeyPems as $pem) {
            $encrypted = self::pkeyEncrypt($ffi, $pem, $sessionKey, $keyLen);
            if (false === $encrypted) {
                return false;
            }
            $encryptedKeys[] = $encrypted;
        }

        return [
            'length' => $totalLen,
            'sealed' => $sealed,
            'encrypted_keys' => $encryptedKeys,
            'iv' => $iv,
        ];
    }

    public static function open(
        string $sealedData,
        string $encryptedKey,
        string $privateKeyPem,
        string $cipherAlgo,
        ?string $iv
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
        if ($ivLen > 0) {
            if (null === $iv || \strlen($iv) !== $ivLen) {
                return false;
            }
        }

        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $keyLen || $keyLen <= 0) {
            return false;
        }

        $sessionKey = self::pkeyDecrypt($ffi, $privateKeyPem, $encryptedKey, $keyLen);
        if (false === $sessionKey) {
            return false;
        }

        $ivBuf = null;
        if ($ivLen > 0 && null !== $iv) {
            $ivBuf = $ffi->new("unsigned char[{$ivLen}]");
            \FFI::memcpy($ivBuf, $iv, $ivLen);
        }

        $sessionKeyBuf = $ffi->new("unsigned char[{$keyLen}]");
        \FFI::memcpy($sessionKeyBuf, $sessionKey, \strlen($sessionKey));

        $dataLen = \strlen($sealedData);
        $outBuf = $ffi->new("unsigned char[{$dataLen}]");

        $ctx = $ffi->EVP_CIPHER_CTX_new();
        if (null === $ctx) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_DecryptInit_ex($ctx, $cipher, null, $sessionKeyBuf, $ivBuf)) {
                return false;
            }

            $len1 = $ffi->new('int');
            $len1->cdata = 0;
            $inBuf = $ffi->new("unsigned char[{$dataLen}]");
            \FFI::memcpy($inBuf, $sealedData, $dataLen);
            if (1 !== (int) $ffi->EVP_DecryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                return false;
            }

            $len2 = $ffi->new('int');
            $len2->cdata = 0;
            $offset = (int) $len1->cdata;
            if (1 !== (int) $ffi->EVP_DecryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                return false;
            }

            $totalLen = $offset + (int) $len2->cdata;
            if ($totalLen <= 0) {
                return false;
            }

            return \FFI::string($outBuf, $totalLen);
        } finally {
            $ffi->EVP_CIPHER_CTX_free($ctx);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $sessionKey
     */
    private static function pkeyEncrypt($ffi, string $publicKeyPem, $sessionKey, int $keyLen): string|false
    {
        $pkey = self::readPublicKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return false;
        }

        $pkeyCtx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $pkeyCtx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_encrypt_init($pkeyCtx)) {
                return false;
            }

            $outLen = $ffi->new('size_t');
            $outLen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($pkeyCtx, null, \FFI::addr($outLen), $sessionKey, $keyLen)) {
                return false;
            }

            $bufLen = (int) $outLen->cdata;
            if ($bufLen <= 0) {
                return false;
            }

            $outBuf = $ffi->new("unsigned char[{$bufLen}]");
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($pkeyCtx, $outBuf, \FFI::addr($outLen), $sessionKey, $keyLen)) {
                return false;
            }

            return \FFI::string($outBuf, (int) $outLen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($pkeyCtx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    private static function pkeyDecrypt($ffi, string $privateKeyPem, string $encryptedKey, int $expectedKeyLen): string|false
    {
        $pkey = self::readPrivateKey($ffi, $privateKeyPem);
        if (null === $pkey) {
            return false;
        }

        $pkeyCtx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $pkeyCtx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        $ekeyLen = \strlen($encryptedKey);
        $ekeyBuf = $ffi->new("unsigned char[{$ekeyLen}]");
        \FFI::memcpy($ekeyBuf, $encryptedKey, $ekeyLen);

        try {
            if (1 !== (int) $ffi->EVP_PKEY_decrypt_init($pkeyCtx)) {
                return false;
            }

            $outLen = $ffi->new('size_t');
            $outLen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($pkeyCtx, null, \FFI::addr($outLen), $ekeyBuf, $ekeyLen)) {
                return false;
            }

            $bufLen = (int) $outLen->cdata;
            if ($bufLen <= 0) {
                return false;
            }

            $outBuf = $ffi->new("unsigned char[{$bufLen}]");
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($pkeyCtx, $outBuf, \FFI::addr($outLen), $ekeyBuf, $ekeyLen)) {
                return false;
            }

            $plainLen = (int) $outLen->cdata;
            if ($plainLen <= 0) {
                return false;
            }

            return \FFI::string($outBuf, $plainLen);
        } finally {
            $ffi->EVP_PKEY_CTX_free($pkeyCtx);
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
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct evp_cipher_ctx_st EVP_CIPHER_CTX;
typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;

BIO *BIO_new_mem_buf(const void *buf, int len);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
void EVP_PKEY_free(EVP_PKEY *pkey);
const EVP_CIPHER *EVP_get_cipherbyname(const char *name);
EVP_CIPHER_CTX *EVP_CIPHER_CTX_new(void);
void EVP_CIPHER_CTX_free(EVP_CIPHER_CTX *ctx);
int EVP_EncryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_EncryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_EncryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
int EVP_DecryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_DecryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_DecryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
EVP_PKEY_CTX *EVP_PKEY_CTX_new(EVP_PKEY *pkey, void *e);
void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
int EVP_PKEY_encrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_encrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen, const unsigned char *in, size_t inlen);
int EVP_PKEY_decrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_decrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen, const unsigned char *in, size_t inlen);
int RAND_bytes(unsigned char *buf, int num);
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
