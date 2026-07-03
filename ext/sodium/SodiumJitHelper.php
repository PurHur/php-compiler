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

    public static function auth(string $message, string $key): string
    {
        return VmSodium::auth($message, $key);
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for __compiler_sodium_auth_verify */
    public static function authVerify(string $mac, string $message, string $key): bool
    {
        return VmSodium::authVerify($mac, $message, $key);
    }

    public static function streamXor(string $message, string $nonce, string $key): string
    {
        return VmSodium::streamXor($message, $nonce, $key);
    }

    public static function streamXchacha20Xor(string $message, string $nonce, string $key): string
    {
        return VmSodium::streamXchacha20Xor($message, $nonce, $key);
    }

    public static function memcmp(string $string1, string $string2): int
    {
        return VmSodium::memcmp($string1, $string2);
    }
}
