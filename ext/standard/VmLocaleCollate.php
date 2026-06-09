<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Locale collation via libc strcoll(3) / strxfrm(3) (issue #4376).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll), PHP_FUNCTION(strxfrm)
 */
final class VmLocaleCollate
{
    private static ?\FFI $ffi = null;

    public static function strcoll(string $a, string $b): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->strcoll($a, $b);
        }

        return VmString::strcmp($a, $b);
    }

    public static function strxfrm(string $string): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return $string;
        }
        $n = (int) $ffi->strxfrm(null, $string, 0);
        if ($n <= 0) {
            return '';
        }
        $buf = \FFI::new('char['.($n + 1).']');
        $ffi->strxfrm($buf, $string, $n + 1);

        return \FFI::string($buf);
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (self::ffiDisabled()) {
            return null;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned long size_t;
int strcoll(const char *s1, const char *s2);
size_t strxfrm(char *dest, const char *src, size_t n);
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

    private static function ffiDisabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');

        return false !== $v && '' !== $v && '0' !== $v;
    }
}
