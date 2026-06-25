<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chdir(2) for VM; falls back to {@see VmChdirPure} when FFI unavailable (#8955).
 *
 * Mirrors {@see JitChdir} — libc chdir with pure PHP bootstrap path.
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class VmChdirNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmChdirPure::available();
    }

    public static function chdir(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmChdirPure::chdir($path);
        }

        try {
            $pathC = $ffi->new('char['.(\strlen($path) + 1).']', false);
            \FFI::memcpy($pathC, $path, \strlen($path));
            $pathC[\strlen($path)] = "\0";

            return 0 === (int) $ffi->chdir(\FFI::addr($pathC[0]));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int chdir(const char *path);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
