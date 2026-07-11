<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_sign()/openssl_verify() for compiled JIT/AOT modules (#3324, #16454, php-in-PHP).
 *
 * SSOT: {@see VmOpenssl}, {@see VmOpensslSignNative}
 * php-src: ext/openssl/openssl.c
 */
final class OpensslSignJitHelper
{
    /** @return string|null null when sign fails (JIT ABI uses null __string__*) */
    public static function signArgv(string $data, string $privateKeyPem, int $algorithm): ?string
    {
        $result = VmOpenssl::sign($data, $privateKeyPem, $algorithm, null);

        return false === $result ? null : $result;
    }

    public static function verifyArgv(string $data, string $signature, string $publicKeyPem, int $algorithm): int
    {
        return VmOpenssl::verify($data, $signature, $publicKeyPem, $algorithm, null);
    }
}
