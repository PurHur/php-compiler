<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_encrypt()/openssl_decrypt() for compiled JIT/AOT modules (#21065, php-in-PHP).
 *
 * SSOT: {@see VmOpenssl}, {@see VmOpensslCipherNative}
 * php-src: ext/openssl/openssl.c
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
        string $iv
    ): ?string {
        $encrypted = VmOpenssl::encrypt($data, $cipherAlgo, $passphrase, $options, $iv, null);
        if (false === $encrypted) {
            return null;
        }
        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            return base64_encode($encrypted);
        }

        return $encrypted;
    }

    /**
     * @return string|null plaintext; null on failure (including invalid base64 when not RAW)
     */
    public static function decryptArgv(
        string $data,
        string $cipherAlgo,
        string $passphrase,
        int $options,
        string $iv
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
        $plain = VmOpenssl::decrypt($payload, $cipherAlgo, $passphrase, $options, $iv, null);

        return false === $plain ? null : $plain;
    }
}
