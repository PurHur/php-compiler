<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc access(2) for VM without host PHP is_* delegation (#8186, pairs JitStat).
 *
 * php-src: ext/standard/filestat.c — php_is_writable / php_is_readable / php_is_executable
 */
final class VmFsAccessNative
{
    public const R_OK = 4;

    public const W_OK = 2;

    public const X_OK = 1;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function access(string $path, int $mode): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            return 0 === (int) $ffi->access($path, $mode);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int access(const char *pathname, int mode);
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
