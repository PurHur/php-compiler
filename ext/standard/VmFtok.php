<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * System V IPC key via libc ftok(3) (issue #6296).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(ftok)
 */
final class VmFtok
{
    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function invoke(string $pathname, int $projId): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->ftok($pathname, $projId);
    }

    public static function lastErrorMessage(): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 'ftok() unavailable';
        }

        $errno = (int) $ffi->__errno_location()[0];

        return 'ftok() failed - '.\FFI::string($ffi->strerror($errno));
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
typedef int key_t;
key_t ftok(const char *pathname, int proj_id);
int *__errno_location(void);
char *strerror(int errnum);
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
