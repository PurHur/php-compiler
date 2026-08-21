<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin libc rmdir(2) ABI when host \\rmdir() is unavailable or would re-enter
 * __phpc_jit_rmdir under NestedJIT AOT (#33403, peer {@see VmFsTouchLibcThinAbi}).
 *
 * Justified thin ABI: removing a directory requires a platform rmdir call;
 * Pure PHP cannot invent that. Quarantined here — not grown into {@see VmFsDirPure}.
 *
 * php-src: ext/standard/filestat.c — php_rmdir / VCWD_RMDIR
 */
final class VmFsDirRmdirLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * rmdir(2) — remove an empty directory.
     *
     * @return bool true on success
     */
    public static function rmdir(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->rmdir($path);
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
int rmdir(const char *pathname);
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
