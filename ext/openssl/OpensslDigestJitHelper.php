<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * openssl_digest() for compiled JIT/AOT modules (#21081, php-in-PHP).
 *
 * SSOT: {@see VmOpenssl::digest}
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_digest)
 */
final class OpensslDigestJitHelper
{
    /**
     * @return string|null digest bytes/hex; null on unknown algorithm (VM warning already emitted)
     */
    public static function digestArgv(string $data, string $method, int $rawOutput): ?string
    {
        $digest = VmOpenssl::digest($data, $method, 0 !== $rawOutput, null);

        return false === $digest ? null : $digest;
    }
}
