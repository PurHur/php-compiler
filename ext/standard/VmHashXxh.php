<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * xxh3 / xxh128 digests via libxxhash FFI (ext/hash/hash_xxhash.c parity, issue #5165).
 *
 * Thin host-ABI layer — no logic in lib/AOT/runtime/*.c.
 *
 * @see https://github.com/php/php-src/blob/master/ext/hash/hash_xxhash.c
 */
final class VmHashXxh
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /** @return list<int> 8-byte big-endian digest */
    public static function xxh3DigestBytes(string $data): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $hash = (int) $ffi->XXH3_64bits($data, \strlen($data));

        return self::u64DigestBytes($hash);
    }

    /** @return list<int> 16-byte canonical digest (high64 || low64, big-endian) */
    public static function xxh128DigestBytes(string $data): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $hash = $ffi->XXH3_128bits($data, \strlen($data));
        $high = (int) $hash->high64;
        $low = (int) $hash->low64;

        return array_merge(self::u64DigestBytes($high), self::u64DigestBytes($low));
    }

    /** @return list<int> big-endian canonical bytes (php-src XXH64_canonicalFromHash) */
    private static function u64DigestBytes(int $value): array
    {
        /** @var list<int> $bytes */
        $bytes = \array_values(\unpack('C8', \pack('J', $value)));

        return $bytes;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false)) {
            self::$ffiUnavailable = true;

            return null;
        }
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower((string) $v)) {
            self::$ffiUnavailable = true;

            return null;
        }
        $libs = ['libxxhash.so.0', 'libxxhash.so', 'xxhash'];
        $cdef = <<<'CDEF'
typedef unsigned long long XXH64_hash_t;
typedef struct { XXH64_hash_t low64; XXH64_hash_t high64; } XXH128_hash_t;
XXH64_hash_t XXH3_64bits(const char* input, size_t length);
XXH128_hash_t XXH3_128bits(const char* input, size_t length);
CDEF;
        foreach ($libs as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
                // try next library name
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }
}
