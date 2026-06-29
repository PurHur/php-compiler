<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/**
 * sodium_crypto_secretbox* for compiled JIT/AOT modules (#13078, php-in-PHP).
 *
 * SSOT: {@see VmSodium}
 * php-src: ext/sodium/libsodium.c
 */
final class SodiumJitHelper
{
    public static function secretbox(string $message, string $nonce, string $key): string
    {
        return VmSodium::secretbox($message, $nonce, $key);
    }

    public static function secretboxOpen(string $ciphertext, string $nonce, string $key): string
    {
        return VmSodium::secretboxOpen($ciphertext, $nonce, $key);
    }
}
