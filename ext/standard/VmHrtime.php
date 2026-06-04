<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hrtime() for VM without host Zend \hrtime() (issue #5174, #3195).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * JIT/AOT: lib/JIT/Builtin/StringHrtime.php (__compiler_hrtime_*).
 */
final class VmHrtime
{
    private const NS_PER_SEC = 1_000_000_000;

    /** Linux CLOCK_MONOTONIC; mirrors StringHrtime when time.h omits the macro. */
    private const CLOCK_MONOTONIC_LINUX = 1;

    private static ?\FFI $ffi = null;

    /**
     * @return int|array{0: int, 1: int}
     */
    public static function hrtime(bool $asNumber = false)
    {
        [$sec, $nsec] = self::readMonotonic();
        if ($asNumber) {
            return (int) ($sec * self::NS_PER_SEC + $nsec);
        }

        return [$sec, $nsec];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function readMonotonic(): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return [0, 0];
        }
        $spec = $ffi->new('struct timespec');
        if (0 !== (int) $ffi->clock_gettime(self::clockId(), \FFI::addr($spec))) {
            return [0, 0];
        }

        return [(int) $spec->tv_sec, (int) $spec->tv_nsec];
    }

    private static function clockId(): int
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            return self::CLOCK_MONOTONIC_LINUX;
        }

        return 0;
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
typedef long time_t;
struct timespec {
    time_t tv_sec;
    long tv_nsec;
};
int clock_gettime(int clock_id, struct timespec *tp);
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
