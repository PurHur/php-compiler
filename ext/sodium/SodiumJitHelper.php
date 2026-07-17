<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/**
 * sodium_crypto_secretbox* for compiled JIT/AOT modules (#13078, php-in-PHP).
 *
 * SSOT for crypto ops: {@see VmSodium}
 * Compare/memcmp: inlined pure algorithms so NestedJitCompile does not miss VmSodium bodies (#20081).
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
        if (\strlen($string1) !== \strlen($string2)) {
            throw new \SodiumException(
                'sodium_memcmp(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        $len = \strlen($string1);
        $d = 0;
        for ($i = 0; $i < $len; ++$i) {
            $d |= \ord($string1[$i]) ^ \ord($string2[$i]);
        }

        return (1 & (($d - 1) >> 8)) - 1;
    }

    public static function compare(string $string1, string $string2): int
    {
        if (\strlen($string1) !== \strlen($string2)) {
            throw new \SodiumException(
                'sodium_compare(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        $gt = 0;
        $eq = 1;
        for ($i = \strlen($string1); $i !== 0; ) {
            --$i;
            $b1 = \ord($string1[$i]);
            $b2 = \ord($string2[$i]);
            $gt |= (($b2 - $b1) >> 8) & $eq;
            $eq &= (($b2 ^ $b1) - 1) >> 8;
        }

        return ($gt + $gt + $eq) - 1;
    }
}
