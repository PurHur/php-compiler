<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin libc utime(2) ABI for touch() when host \\touch() is unavailable or would
 * re-enter __compiler_touch under NestedJIT AOT (#28995, #12145).
 *
 * Justified thin ABI: setting file times requires a platform utime/utimes call;
 * Pure PHP cannot invent mtime/atime. Quarantined here — not in {@see VmFsTouchPure}.
 *
 * php-src: ext/standard/filestat.c — php_touch / VCWD_UTIME
 */
final class VmFsTouchLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * utime(2) with explicit actime/modtime (seconds since epoch).
     *
     * @return bool true on success
     */
    public static function utime(string $path, int $atime, int $mtime): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            // Linux utimbuf is { time_t actime; time_t modtime; } — two adjacent longs.
            $times = $ffi->new('long[2]');
            $times[0] = $atime;
            $times[1] = $mtime;

            return 0 === (int) $ffi->utime($path, \FFI::addr($times[0]));
        } catch (\Throwable) {
            return false;
        }
    }

    /** utime(path, NULL) — both times → now. */
    public static function utimeNow(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->utime($path, null);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower((string) $v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int utime(const char *filename, const long *times);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$unavailable = true;

        return null;
    }
}
