<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_encrypt()/openssl_decrypt() for compiled JIT/AOT modules (#21065, AEAD #21135, php-in-PHP).
 *
 * SSOT: {@see VmOpenssl}, {@see VmOpensslCipherNative}
 * php-src: ext/openssl/openssl.c
 *
 * When $tagMode != 0, ciphertext is returned and the AEAD tag (or null for non-AEAD) is stashed on
 * {@see VmOpensslCipherNative} for takeEncryptTag* so LLVM can write &$tag (no NestedJIT statics).
 */
final class OpensslEncryptJitHelper
{
    /**
     * @return string|null ciphertext (base64 when OPENSSL_RAW_DATA unset); null on failure
     */
    public static function encryptArgv(
        string $data,
        string $cipherAlgo,
        string $passphrase,
        int $options,
        string $iv,
        string $aad = '',
        int $tagLength = 16,
        int $tagMode = 0
    ): ?string {
        VmOpensslCipherNative::clearJitPendingTag();

        $wantTag = 0 !== $tagMode;
        $cipher = strtolower($cipherAlgo);
        $isAead = OpensslCipherRegistry::isAeadCipher($cipher);

        if ($isAead && !$wantTag) {
            VmOpenssl::userWarningForFrame(
                'openssl_encrypt(): A tag should be provided when using AEAD mode',
                null
            );

            return null;
        }

        if (!VmOpensslCipherNative::available()) {
            VmOpenssl::userWarningForFrame(
                'openssl_encrypt(): OpenSSL cipher encryption is unavailable in this compiler build',
                null
            );

            return null;
        }
        if (false === OpensslCipherRegistry::cipherIvLength($cipher)) {
            VmOpenssl::userWarningForFrame('openssl_encrypt(): Unknown cipher algorithm', null);

            return null;
        }
        if (!$isAead) {
            $ivLen = OpensslCipherRegistry::cipherIvLength($cipher);
            if (false !== $ivLen && $ivLen > 0 && \strlen($iv) !== $ivLen) {
                VmOpenssl::userWarningForFrame(\sprintf(
                    'openssl_encrypt(): IV passed is only %d bytes long, cipher expects an IV of precisely %d bytes, padding with \\0',
                    \strlen($iv),
                    $ivLen
                ), null);

                return null;
            }
        }
        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipher);
        if (false === $keyLen || \strlen($passphrase) !== $keyLen) {
            VmOpenssl::userWarningForFrame('openssl_encrypt(): Invalid key length', null);

            return null;
        }

        $zeroPadding = 0 !== ($options & OpensslConstants::OPENSSL_ZERO_PADDING);
        $result = VmOpensslCipherNative::encrypt(
            $data,
            $cipher,
            $passphrase,
            $iv,
            $zeroPadding,
            $aad,
            $tagLength,
            $wantTag
        );
        if (false === $result) {
            if ($isAead && $wantTag) {
                VmOpenssl::userWarningForFrame(
                    'openssl_encrypt(): Retrieving verification tag failed',
                    null
                );
            } else {
                VmOpenssl::userWarningForFrame('openssl_encrypt(): Encryption failed', null);
            }

            return null;
        }

        $ct = $result['ciphertext'];
        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $ct = base64_encode($ct);
        }

        if ($wantTag) {
            if ($isAead) {
                $tag = $result['tag'] ?? '';
                if ('' === $tag) {
                    VmOpenssl::userWarningForFrame(
                        'openssl_encrypt(): Retrieving verification tag failed',
                        null
                    );

                    return null;
                }
                VmOpensslCipherNative::setJitPendingTag($tag, false);
            } else {
                VmOpensslCipherNative::setJitPendingTag(null, true);
            }
        }

        return $ct;
    }

    /** Tag bytes after a successful encryptArgv with tagMode!=0 (empty if null-assign). */
    public static function takeEncryptTag(): string
    {
        return VmOpensslCipherNative::takeJitPendingTag();
    }

    /** 1 when &$tag must be set to null (non-AEAD with tag arg). */
    public static function takeEncryptTagIsNull(): int
    {
        return VmOpensslCipherNative::takeJitPendingTagIsNull();
    }

    /**
     * @return string|null plaintext; null on failure (including invalid base64 when not RAW)
     */
    public static function decryptArgv(
        string $data,
        string $cipherAlgo,
        string $passphrase,
        int $options,
        string $iv,
        string $tag = '',
        string $aad = ''
    ): ?string {
        $payload = $data;
        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $decoded = base64_decode($data, true);
            if (false === $decoded) {
                VmOpenssl::userWarningForFrame('openssl_decrypt(): Input is not valid base64', null);

                return null;
            }
            $payload = $decoded;
        }
        $plain = VmOpenssl::decrypt($payload, $cipherAlgo, $passphrase, $options, $iv, null, $tag, $aad);

        return false === $plain ? null : $plain;
    }
}
