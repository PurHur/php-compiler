<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Monotonic clock for VM/JIT/AOT (issue #7315, #5174, #9018, #10859).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * Primary: libc FFI when ext/ffi is loaded. Fallback: /proc/uptime (µs only, #7315 bootstrap).
 * JIT/AOT: ext/standard/HrtimeJitHelper.php via StringHrtimeRuntime (#9182).
 */
final class VmHrtimeNative
{
    public const NS_PER_SEC = 1_000_000_000;

    public const CLOCK_REALTIME = 0;

    public const CLOCK_MONOTONIC = 1;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /**
     * @return array{0: int, 1: int} seconds and nanoseconds
     */
    public static function readMonotonic(): array
    {
        return self::readClock(self::CLOCK_MONOTONIC) ?? [0, 0];
    }

    /**
     * @return array{0: int, 1: int}|null seconds and nanoseconds
     */
    public static function readClock(int $clockId): ?array
    {
        $ffiPair = self::readClockFfi($clockId);
        if (null !== $ffiPair) {
            return $ffiPair;
        }

        if (self::CLOCK_MONOTONIC === $clockId && 'Linux' === \PHP_OS_FAMILY) {
            return self::readMonotonicLinux();
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function readClockFfi(int $clockId): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        $ts = $ffi->new('struct timespec');
        if (0 !== (int) $ffi->clock_gettime($clockId, \FFI::addr($ts))) {
            return null;
        }

        return [(int) $ts->tv_sec, (int) $ts->tv_nsec];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function readMonotonicFfi(): ?array
    {
        return self::readClockFfi(self::CLOCK_MONOTONIC);
    }

    /**
     * Parse first field of /proc/uptime into [sec, nsec] (µs precision fallback; #7287).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseUptimeRaw(string $raw): ?array
    {
        if ('' === $raw) {
            return null;
        }
        $space = \strpos($raw, ' ');
        if (false === $space) {
            return null;
        }
        $secs = (float) \substr($raw, 0, $space);
        if ($secs < 0.0) {
            return null;
        }
        $sec = (int) $secs;
        $nsec = (int) \round(($secs - $sec) * self::NS_PER_SEC);
        if ($nsec >= self::NS_PER_SEC) {
            ++$sec;
            $nsec -= self::NS_PER_SEC;
        }

        return [$sec, $nsec];
    }

    /**
     * CLOCK_MONOTONIC approximation via /proc/uptime when FFI is unavailable (#7315).
     *
     * @return array{0: int, 1: int}
     */
    private static function readMonotonicLinux(): array
    {
        if (!\is_readable('/proc/uptime')) {
            return [0, 0];
        }
        $raw = VmFsReadNative::read('/proc/uptime');
        if (false === $raw) {
            return [0, 0];
        }

        return self::parseUptimeRaw($raw) ?? [0, 0];
    }

    private static function ffi(): ?\FFI
    {
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

        $cdef = <<<'CDEF'
typedef long time_t;
typedef int clockid_t;
struct timespec {
    time_t tv_sec;
    long tv_nsec;
};
#define CLOCK_REALTIME 0
#define CLOCK_MONOTONIC 1
int clock_gettime(clockid_t clk_id, struct timespec *tp);
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
}
