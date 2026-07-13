<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * EVP_PKEY_derive via libcrypto FFI (php-src ext/openssl/pkey.c; issue #15428).
 */
final class VmOpensslPkeyDeriveNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    private const EVP_PKEY_DH = 28;

    /**
     * openssl_dh_compute_key() — raw peer DH public bytes + local private key (php-src ext/openssl/openssl_backend_v3.c; #6596).
     */
    public static function dhComputeKey(string $privateKeyPem, string $pubKeyBytes): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $privateKey = self::readPrivateKey($ffi, $privateKeyPem);
        if (null === $privateKey) {
            return false;
        }

        if (self::EVP_PKEY_DH !== (int) $ffi->EVP_PKEY_get_base_id($privateKey)) {
            $ffi->EVP_PKEY_free($privateKey);

            return false;
        }

        $peerKey = $ffi->EVP_PKEY_new();
        if (null === $peerKey) {
            $ffi->EVP_PKEY_free($privateKey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_copy_parameters($peerKey, $privateKey)) {
                return false;
            }
            $pubLen = \strlen($pubKeyBytes);
            if ($pubLen <= 0) {
                return false;
            }
            $pubBuf = $ffi->new("unsigned char[{$pubLen}]");
            \FFI::memcpy($pubBuf, $pubKeyBytes, $pubLen);
            if (1 !== (int) $ffi->EVP_PKEY_set1_encoded_public_key(
                $peerKey,
                $pubBuf,
                $pubLen
            )) {
                return false;
            }

            return self::deriveWithPeer($ffi, $privateKey, $peerKey, 0);
        } finally {
            $ffi->EVP_PKEY_free($peerKey);
            $ffi->EVP_PKEY_free($privateKey);
        }
    }

    public static function derive(string $publicKeyPem, string $privateKeyPem, int $keyLength = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $privateKey = self::readPrivateKey($ffi, $privateKeyPem);
        if (null === $privateKey) {
            return false;
        }

        $publicKey = self::readPublicKey($ffi, $publicKeyPem);
        if (null === $publicKey) {
            $ffi->EVP_PKEY_free($privateKey);

            return false;
        }

        try {
            return self::deriveWithPeer($ffi, $privateKey, $publicKey, $keyLength);
        } finally {
            $ffi->EVP_PKEY_free($publicKey);
            $ffi->EVP_PKEY_free($privateKey);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $privateKey
     * @param \FFI\CData $peerKey
     */
    private static function deriveWithPeer($ffi, $privateKey, $peerKey, int $keyLength): string|false
    {
        $ctx = $ffi->EVP_PKEY_CTX_new($privateKey, null);
        if (null === $ctx) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_derive_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_derive_set_peer($ctx, $peerKey)) {
                return false;
            }

            $secretLen = $ffi->new('size_t');
            $secretLen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_derive($ctx, null, \FFI::addr($secretLen))) {
                return false;
            }

            $length = (int) $secretLen->cdata;
            if ($keyLength > 0) {
                $length = $keyLength;
            }
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            $secretLen->cdata = $length;
            if (1 !== (int) $ffi->EVP_PKEY_derive($ctx, $buf, \FFI::addr($secretLen))) {
                return false;
            }

            return \FFI::string($buf, (int) $secretLen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
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
typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;

BIO *BIO_new_mem_buf(const void *buf, int len);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *EVP_PKEY_new(void);
int EVP_PKEY_get_base_id(const EVP_PKEY *pkey);
int EVP_PKEY_copy_parameters(EVP_PKEY *to, const EVP_PKEY *from);
int EVP_PKEY_set1_encoded_public_key(EVP_PKEY *pkey, const unsigned char *pub, size_t len);
void EVP_PKEY_free(EVP_PKEY *pkey);
EVP_PKEY_CTX *EVP_PKEY_CTX_new(EVP_PKEY *pkey, void *e);
void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
int EVP_PKEY_derive_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_derive_set_peer(EVP_PKEY_CTX *ctx, EVP_PKEY *peer);
int EVP_PKEY_derive(EVP_PKEY_CTX *ctx, unsigned char *key, size_t *keylen);
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
