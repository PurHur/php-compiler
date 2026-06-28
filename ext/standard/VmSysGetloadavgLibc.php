<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin libc getloadavg(3) FFI for full double precision (#13020, php-in-PHP).
 *
 * Fallback when unavailable: {@see VmSysGetloadavgPure} (/proc/loadavg, ~2 decimal digits).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgLibc
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg(): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $loads = $ffi->new('double[3]');
        $n = (int) $ffi->getloadavg($loads, 3);
        if ($n < 1) {
            return false;
        }

        $out = [];
        for ($i = 0; $i < 3; ++$i) {
            $out[] = $i < $n ? (float) $loads[$i] : 0.0;
        }

        /** @var array{0: float, 1: float, 2: float} */
        return [$out[0], $out[1], $out[2]];
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

        $cdef = 'int getloadavg(double loadavg[], int nelem);';

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
}
