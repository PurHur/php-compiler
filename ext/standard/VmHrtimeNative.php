<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Monotonic clock for VM without host ext/ffi (issue #7315, #5174, #9018).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * JIT/AOT: ext/standard/HrtimeJitHelper.php via StringHrtimeRuntime (#9182).
 */
final class VmHrtimeNative
{
    public const NS_PER_SEC = 1_000_000_000;

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
     * Parse first field of /proc/uptime into [sec, nsec] (shared with JIT HrtimeJitHelper).
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
        if (false === $raw) {
            return [0, 0];
        }

        return self::parseUptimeRaw($raw) ?? [0, 0];
    }
}
