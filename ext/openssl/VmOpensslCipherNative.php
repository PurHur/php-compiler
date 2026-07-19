<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * Symmetric EVP cipher encrypt/decrypt via libcrypto FFI (php-src ext/openssl/openssl.c; #18594, AEAD #21135).
 */
final class VmOpensslCipherNative
{
    /** OpenSSL EVP_CTRL_AEAD_* (shared with GCM aliases). */
    private const EVP_CTRL_AEAD_SET_IVLEN = 0x9;

    private const EVP_CTRL_AEAD_GET_TAG = 0x10;

    private const EVP_CTRL_AEAD_SET_TAG = 0x11;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** Request-scoped tag handoff for NestedJIT &$tag writeback (#21135). */
    private static ?string $jitPendingTag = null;

    private static bool $jitPendingTagIsNull = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function setJitPendingTag(?string $tag, bool $isNull): void
    {
        self::$jitPendingTag = $tag;
        self::$jitPendingTagIsNull = $isNull;
    }

    public static function takeJitPendingTag(): string
    {
        $tag = self::$jitPendingTag ?? '';
        self::$jitPendingTag = null;

        return $tag;
    }

    public static function takeJitPendingTagIsNull(): int
    {
        $isNull = self::$jitPendingTagIsNull ? 1 : 0;
        self::$jitPendingTagIsNull = false;

        return $isNull;
    }

    public static function clearJitPendingTag(): void
    {
        self::$jitPendingTag = null;
        self::$jitPendingTagIsNull = false;
    }

    /**
     * @return array{ciphertext: string, tag: string|null}|false
     *                                                    tag is non-null only for AEAD when $wantTag
     */
    public static function encrypt(
        string $data,
        string $cipherAlgo,
        string $key,
        string $iv,
        bool $zeroPadding,
        string $aad = '',
        int $tagLength = 16,
        bool $wantTag = false
    ): array|false {
        $result = self::cipher(
            $cipherAlgo,
            $key,
            $iv,
            $data,
            true,
            $zeroPadding,
            $aad,
            '',
            $tagLength,
            $wantTag
        );
        if (false === $result) {
            return false;
        }

        return [
            'ciphertext' => $result['payload'],
            'tag' => $result['tag'],
        ];
    }

    public static function decrypt(
        string $data,
        string $cipherAlgo,
        string $key,
        string $iv,
        bool $zeroPadding,
        string $aad = '',
        string $tag = ''
    ): string|false {
        $result = self::cipher(
            $cipherAlgo,
            $key,
            $iv,
            $data,
            false,
            $zeroPadding,
            $aad,
            $tag,
            \strlen($tag),
            false
        );
        if (false === $result) {
            return false;
        }

        return $result['payload'];
    }

    /**
     * @return array{payload: string, tag: string|null}|false
     */
    private static function cipher(
        string $cipherAlgo,
        string $key,
        string $iv,
        string $data,
        bool $encrypt,
        bool $zeroPadding,
        string $aad,
        string $tagIn,
        int $tagLength,
        bool $wantTag
    ): array|false {
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
        $isAead = OpensslCipherRegistry::isAeadCipher($cipherAlgo);
        if (!$isAead && $ivLen > 0 && \strlen($iv) !== $ivLen) {
            return false;
        }
        if ($isAead && $ivLen > 0 && 0 === \strlen($iv)) {
            return false;
        }

        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $keyLen || $keyLen <= 0) {
            return false;
        }
        if (\strlen($key) !== $keyLen) {
            return false;
        }

        $dataLen = \strlen($data);
        $outBufLen = $encrypt ? $dataLen + 32 : $dataLen;
        if ($outBufLen <= 0) {
            $outBufLen = 32;
        }
        $outBuf = $ffi->new("unsigned char[{$outBufLen}]");

        $keyBuf = $ffi->new("unsigned char[{$keyLen}]");
        \FFI::memcpy($keyBuf, $key, $keyLen);

        $ivBuf = null;
        $ivBytes = \strlen($iv);
        if ($ivBytes > 0) {
            $ivBuf = $ffi->new("unsigned char[{$ivBytes}]");
            \FFI::memcpy($ivBuf, $iv, $ivBytes);
        } elseif ($ivLen > 0) {
            return false;
        }

        $ctx = $ffi->EVP_CIPHER_CTX_new();
        if (null === $ctx) {
            return false;
        }

        try {
            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptInit_ex($ctx, $cipher, null, null, null)) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptInit_ex($ctx, $cipher, null, null, null)) {
                    return false;
                }
            }

            if ($isAead) {
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_ctrl($ctx, self::EVP_CTRL_AEAD_SET_IVLEN, $ivBytes, null)) {
                    return false;
                }
            }

            if ($isAead && OpensslCipherRegistry::aeadSetsTagLengthWhenEncrypting($cipherAlgo) && $encrypt) {
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_ctrl($ctx, self::EVP_CTRL_AEAD_SET_TAG, $tagLength, null)) {
                    return false;
                }
            }

            if (!$encrypt && $isAead && '' !== $tagIn) {
                $tagLenIn = \strlen($tagIn);
                $tagBuf = $ffi->new("unsigned char[{$tagLenIn}]");
                \FFI::memcpy($tagBuf, $tagIn, $tagLenIn);
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_ctrl($ctx, self::EVP_CTRL_AEAD_SET_TAG, $tagLenIn, $tagBuf)) {
                    return false;
                }
            }

            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptInit_ex($ctx, null, null, $keyBuf, $ivBuf)) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptInit_ex($ctx, null, null, $keyBuf, $ivBuf)) {
                    return false;
                }
            }

            if ($zeroPadding) {
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_set_padding($ctx, 0)) {
                    return false;
                }
            }

            $lenTmp = $ffi->new('int');
            $lenTmp->cdata = 0;
            if ($isAead) {
                $aadLen = \strlen($aad);
                $aadBuf = null;
                if ($aadLen > 0) {
                    $aadBuf = $ffi->new("unsigned char[{$aadLen}]");
                    \FFI::memcpy($aadBuf, $aad, $aadLen);
                }
                if ($encrypt) {
                    if (1 !== (int) $ffi->EVP_EncryptUpdate($ctx, null, \FFI::addr($lenTmp), $aadBuf, $aadLen)) {
                        return false;
                    }
                } else {
                    if (1 !== (int) $ffi->EVP_DecryptUpdate($ctx, null, \FFI::addr($lenTmp), $aadBuf, $aadLen)) {
                        return false;
                    }
                }
            }

            $len1 = $ffi->new('int');
            $len1->cdata = 0;
            // EVP_*Update(inl=0) accepts NULL in — avoid zero-size FFI\CData (#19016).
            $inBuf = null;
            if ($dataLen > 0) {
                $inBuf = $ffi->new("unsigned char[{$dataLen}]");
                \FFI::memcpy($inBuf, $data, $dataLen);
            }

            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptUpdate($ctx, $outBuf, \FFI::addr($len1), $inBuf, $dataLen)) {
                    return false;
                }
            }

            $len2 = $ffi->new('int');
            $len2->cdata = 0;
            $offset = (int) $len1->cdata;
            if ($encrypt) {
                if (1 !== (int) $ffi->EVP_EncryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                    return false;
                }
            } else {
                if (1 !== (int) $ffi->EVP_DecryptFinal_ex($ctx, $outBuf + $offset, \FFI::addr($len2))) {
                    return false;
                }
            }

            $totalLen = $offset + (int) $len2->cdata;
            if ($totalLen < 0) {
                return false;
            }

            $payload = \FFI::string($outBuf, $totalLen);
            $tagOut = null;
            if ($encrypt && $isAead && $wantTag) {
                if ($tagLength <= 0) {
                    return false;
                }
                $tagBuf = $ffi->new("unsigned char[{$tagLength}]");
                if (1 !== (int) $ffi->EVP_CIPHER_CTX_ctrl($ctx, self::EVP_CTRL_AEAD_GET_TAG, $tagLength, $tagBuf)) {
                    return false;
                }
                $tagOut = \FFI::string($tagBuf, $tagLength);
            }

            return [
                'payload' => $payload,
                'tag' => $tagOut,
            ];
        } finally {
            $ffi->EVP_CIPHER_CTX_free($ctx);
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
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct evp_cipher_ctx_st EVP_CIPHER_CTX;

const EVP_CIPHER *EVP_get_cipherbyname(const char *name);
EVP_CIPHER_CTX *EVP_CIPHER_CTX_new(void);
void EVP_CIPHER_CTX_free(EVP_CIPHER_CTX *ctx);
int EVP_CIPHER_CTX_set_padding(EVP_CIPHER_CTX *ctx, int padding);
int EVP_CIPHER_CTX_ctrl(EVP_CIPHER_CTX *ctx, int type, int arg, void *ptr);
int EVP_EncryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_EncryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_EncryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
int EVP_DecryptInit_ex(EVP_CIPHER_CTX *ctx, const EVP_CIPHER *type, void *impl, const unsigned char *key, const unsigned char *iv);
int EVP_DecryptUpdate(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl, const unsigned char *in, int inl);
int EVP_DecryptFinal_ex(EVP_CIPHER_CTX *ctx, unsigned char *out, int *outl);
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
