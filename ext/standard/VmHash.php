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
