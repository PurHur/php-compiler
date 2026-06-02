<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM hash() / hash_hmac() — delegates to host PHP for reference-compatible digests (issue #179).
 */
final class VmHash
{
    private const SUPPORTED = ['sha256', 'sha1', 'md5'];

    public static function hash(string $algo, string $data, bool $raw = false) {
        $algo = strtolower($algo);
        if (!\in_array($algo, self::SUPPORTED, true)) {
            return false;
        }

        return \hash($algo, $data, $raw);
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw = false) {
        $algo = strtolower($algo);
        if (!\in_array($algo, self::SUPPORTED, true)) {
            return false;
        }

        return \hash_hmac($algo, $data, $key, $raw);
    }

    /**
     * hash_pbkdf2() — delegates to host PHP (issue #3773, ext/hash/hash_pbkdf2.c parity).
     *
     * @throws \ValueError unknown algorithm (PHP 8+)
     */
    public static function hashPbkdf2(
        string $algo,
        string $password,
        string $salt,
        int $iterations,
        int $length = 0,
        bool $raw = false
    ): string {
        return \hash_pbkdf2($algo, $password, $salt, $iterations, $length, $raw);
    }

    /** Timing-safe string compare for hash_equals() (issue #2179). */
    public static function equals(string $known, string $user): bool
    {
        if (\strlen($known) !== \strlen($user)) {
            return false;
        }
        $result = 0;
        $len = \strlen($known);
        for ($i = 0; $i < $len; $i++) {
            $result |= \ord($known[$i]) ^ \ord($user[$i]);
        }

        return 0 === $result;
    }
}
