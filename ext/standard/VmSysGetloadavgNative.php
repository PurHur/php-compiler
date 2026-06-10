<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc getloadavg(3) for VM without Zend host builtin delegation (#4607 VM phase, #3464).
 *
 * Mirrors {@see JitSysGetloadavg} — no Zend host builtin delegation on VM.
 *
 * php-src: ext/standard/syslog.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgNative
{
    private const LOAD_COUNT = 3;

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

        try {
            $buf = $ffi->new('double['.self::LOAD_COUNT.']');
            $ret = (int) $ffi->getloadavg($buf, self::LOAD_COUNT);
            if ($ret < 0) {
                return false;
            }

            return [
                (float) $buf[0],
                (float) $buf[1],
                (float) $buf[2],
            ];
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
typedef double loadavg_t;
int getloadavg(double loadavg[], int nelem);
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
