<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Monotonic clock for VM/JIT/AOT (issue #7315, #5174, #9018, #10859, #12144, #12225).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * Primary: libc clock_gettime (ns precision). Fallback: /proc/uptime (µs) on Linux bootstrap.
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
        $pair = self::readClockGettime($clockId);
        if (null !== $pair) {
            return $pair;
        }

        return match ($clockId) {
            self::CLOCK_MONOTONIC => self::readMonotonicLinuxFallback(),
            self::CLOCK_REALTIME => self::readRealtimeMicrotimeFallback(),
            default => null,
        };
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function readClockGettime(int $clockId): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        try {
            $ts = $ffi->new('struct timespec');
            if (0 !== (int) $ffi->clock_gettime($clockId, \FFI::addr($ts))) {
                return null;
            }
            $sec = (int) $ts->tv_sec;
            $nsec = (int) $ts->tv_nsec;
            if ($nsec < 0 || $nsec >= self::NS_PER_SEC) {
                return null;
            }

            return [$sec, $nsec];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function readRealtimeMicrotimeFallback(): ?array
    {
        if (!\function_exists('microtime')) {
            return null;
        }
        $seconds = \microtime(true);
        $sec = (int) $seconds;
        $nsec = (int) \round(($seconds - $sec) * (float) self::NS_PER_SEC);
        if ($nsec >= self::NS_PER_SEC) {
            ++$sec;
            $nsec -= self::NS_PER_SEC;
        }

        return [$sec, $nsec];
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
     * CLOCK_MONOTONIC via /proc/uptime when clock_gettime unavailable (#7315, #12144).
     *
     * @return array{0: int, 1: int}|null
     */
    private static function readMonotonicLinuxFallback(): ?array
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            return null;
        }
        $raw = VmFsReadNative::read('/proc/uptime');
        if (false === $raw) {
            return null;
        }

        return self::parseUptimeRaw($raw);
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
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef(
                    'struct timespec { long tv_sec; long tv_nsec; };
                    int clock_gettime(int clock_id, struct timespec *tp);',
                    $lib
                );

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
