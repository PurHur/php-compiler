<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Monotonic clock for VM without host ext/ffi (issue #7315, #5174).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * JIT/AOT: lib/JIT/Builtin/StringHrtime.php (__compiler_hrtime_* + libc clock_gettime).
 */
final class VmHrtimeNative
{
    private const NS_PER_SEC = 1_000_000_000;

    /**
     * @return array{0: int, 1: int} seconds and nanoseconds (Linux: /proc/uptime monotonic boot time)
     */
    public static function readMonotonic(): array
    {
        if ('Linux' === \PHP_OS_FAMILY) {
            return self::readMonotonicLinux();
        }

        return [0, 0];
    }

    /**
     * CLOCK_MONOTONIC approximation via /proc/uptime (VmFsReadNative; #7287, #8426).
     *
     * @return array{0: int, 1: int}
     */
    private static function readMonotonicLinux(): array
    {
        if (!\is_readable('/proc/uptime')) {
            return [0, 0];
        }
        $raw = VmFsReadNative::read('/proc/uptime');
        if (false === $raw || '' === $raw) {
            return [0, 0];
        }
        $space = \strpos($raw, ' ');
        if (false === $space) {
            return [0, 0];
        }
        $secs = (float) \substr($raw, 0, $space);
        if ($secs < 0.0) {
            return [0, 0];
        }
        $sec = (int) $secs;
        $nsec = (int) \round(($secs - $sec) * self::NS_PER_SEC);
        if ($nsec >= self::NS_PER_SEC) {
            ++$sec;
            $nsec -= self::NS_PER_SEC;
        }

        return [$sec, $nsec];
    }
}
