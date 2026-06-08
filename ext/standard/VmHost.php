<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Host introspection via libc FFI (issue #3465, #5022).
 *
 * Mirrors {@see JitGethostname} — no Zend host-PHP gethostname() delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class VmHost
{
    /** php-src HOST_NAME_MAX; Linux gethostname(2) uses 256-byte buffer in basic_functions.c. */
    private const BUF_SIZE = 256;

    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /** @return string|false */
    public static function gethostname()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $buf = $ffi->new('char['.self::BUF_SIZE.']');
        $ret = $ffi->gethostname(\FFI::addr($buf[0]), self::BUF_SIZE);
        if (0 !== $ret) {
            return false;
        }

        $host = \FFI::string($buf);
        if ('' === $host) {
            return false;
        }

        return $host;
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
typedef unsigned long size_t;
int gethostname(char *name, size_t len);
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
