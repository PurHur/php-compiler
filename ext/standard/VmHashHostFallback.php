<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash() host ext/hash fallback for registry-listed digests not yet in VmHashNative (#12903).
 *
 * Guarded delegation to Zend hash() when available (same pattern as VmHashXxhPure).
 * php-src: ext/hash/hash.c — php_hash_hashtable entries for murmur/tiger/whirlpool/gost.
 */
final class VmHashHostFallback
{
    private static bool $inDigest = false;

    /** @var list<string> */
    private const FALLBACK_ALGOS = [
        'murmur3a',
        'murmur3c',
        'murmur3f',
        'whirlpool',
        'tiger128,3',
        'tiger160,3',
        'tiger192,3',
        'tiger128,4',
        'tiger160,4',
        'tiger192,4',
        'gost',
        'gost-crypto',
    ];

    public static function supportsDigest(string $algo): bool
    {
        if (!\in_array($algo, self::FALLBACK_ALGOS, true)) {
            return false;
        }
        if (self::$inDigest || !\function_exists('hash') || !\function_exists('hash_algos')) {
            return false;
        }

        return \in_array($algo, \hash_algos(), true);
    }

    public static function supportsHmac(string $algo): bool
    {
        if (!\in_array($algo, HashAlgosRegistry::HMAC_ALGOS, true)) {
            return false;
        }
        if (self::$inDigest || !\function_exists('hash_hmac') || !\function_exists('hash_hmac_algos')) {
            return false;
        }

        return \in_array($algo, \hash_hmac_algos(), true);
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
