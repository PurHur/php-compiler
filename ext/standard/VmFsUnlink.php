<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unlink(2) for VM without calling host PHP unlink() (bootstrap #5063, php-src filestat.c).
 */
final class VmFsUnlink
{
    private static ?\FFI $ffi = null;

    public static function unlink(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return 0 === (int) $ffi->unlink($path);
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $cdef = <<<'CDEF'
int unlink(const char *pathname);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
