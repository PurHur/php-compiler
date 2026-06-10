<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM proc_nice() via libc nice(3) FFI (#7862, #5181).
 *
 * Mirrors {@see JitProcNice} — no Zend host builtin delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(proc_nice)
 */
final class VmProcNiceNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function proc_nice(int $priority): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $errnoPtr = $ffi->__errno_location();
        $errnoPtr[0] = 0;

        $ret = (int) $ffi->nice($priority);
        if (-1 === $ret && 0 !== (int) $errnoPtr[0]) {
            return false;
        }

        return true;
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
int nice(int inc);
int *__errno_location(void);
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
