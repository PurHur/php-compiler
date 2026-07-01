<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin libc FILE* ABI for JIT embed stream handle lifecycle (#14457).
 *
 * VM stream paths prefer {@see VmPhpFdStream} fd SSOT; this quarantines the minimal
 * fclose/feof/fflush/pclose surface still required for LLVM fopen FILE* registration.
 *
 * php-src: ext/standard/streamsfuncs.c
 */
final class StreamLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function fclose(int $fpPtr): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fclose($ffi->cast('void*', $fpPtr));
    }

    public static function feof(int $fpPtr): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 1;
        }

        return (int) $ffi->feof($ffi->cast('void*', $fpPtr));
    }

    public static function fflush(int $fpPtr): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->fflush($ffi->cast('void*', $fpPtr));
    }

    public static function pclose(int $fpPtr): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->pclose($ffi->cast('void*', $fpPtr));
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
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
typedef struct _IO_FILE FILE;
int fclose(FILE *stream);
int feof(FILE *stream);
int fflush(FILE *stream);
int pclose(FILE *stream);
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
