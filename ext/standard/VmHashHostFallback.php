<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash() host ext/hash fallback for registry-listed digests not yet in VmHashNative (#12903, #13629).
 *
 * Guarded delegation to Zend hash() when available (same pattern as VmHashXxhPure).
 * php-src: ext/hash/hash.c — php_hash_hashtable entries for legacy/optional digests.
 */
final class VmHashHostFallback
{
    private static bool $inDigest = false;

    public static function supportsDigest(string $algo): bool
    {
        $lower = \strtolower($algo);
        if (!\in_array($lower, HashAlgosRegistry::ALL_ALGOS, true)) {
            return false;
        }
        if (VmHashNative::supports($algo)) {
            return false;
        }
        if (self::$inDigest || !\function_exists('hash') || !\function_exists('hash_algos')) {
            return false;
        }

        return \in_array($lower, \hash_algos(), true);
    }

    public static function supportsHmac(string $algo): bool
    {
        $lower = \strtolower($algo);
        if (!\in_array($lower, HashAlgosRegistry::HMAC_ALGOS, true)) {
            return false;
        }
        if (VmHashNative::supports($algo)) {
            return false;
        }
        if (self::$inDigest || !\function_exists('hash_hmac') || !\function_exists('hash_hmac_algos')) {
            return false;
        }

        return \in_array($lower, \hash_hmac_algos(), true);
    }

    public static function hash(string $algo, string $data, bool $raw = false): string|false
    {
        $lower = \strtolower($algo);
        if (!self::supportsDigest($lower)) {
            return false;
        }
        self::$inDigest = true;
        try {
            return \hash($lower, $data, $raw);
        } finally {
            self::$inDigest = false;
        }
    }

    public static function hashHmac(string $algo, string $data, string $key, bool $raw = false): string|false
    {
        $lower = \strtolower($algo);
        if (!self::supportsHmac($lower)) {
            return false;
        }
        self::$inDigest = true;
        try {
            return \hash_hmac($lower, $data, $key, $raw);
        } finally {
            self::$inDigest = false;
        }
    }
}
