<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Monotonic clock for VM/JIT/AOT (issue #7315, #5174, #9018, #10859, #12144, #12225, #12236).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * Pure PHP: /proc/uptime (Linux monotonic, µs) + microtime(true) (realtime).
 * JIT/AOT: ext/standard/HrtimeJitHelper.php via StringHrtimeRuntime (#9182).
 */
final class VmHrtimeNative
{
    public const NS_PER_SEC = 1_000_000_000;

    public const CLOCK_REALTIME = 0;

    public const CLOCK_MONOTONIC = 1;

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
        return match ($clockId) {
            self::CLOCK_MONOTONIC => self::readMonotonicLinuxFallback(),
            self::CLOCK_REALTIME => self::readRealtimeMicrotimeFallback(),
            default => null,
        };
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
     * CLOCK_MONOTONIC via /proc/uptime + microtime sub-ms refinement (#7315, #12144, #12236, #12279).
     *
     * /proc/uptime is coarse (µs–cs); overlay microtime(true) sub-microsecond digits for
     * hrtime()[1] % 1000 parity without libc clock_gettime FFI.
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
        $pair = self::parseUptimeRaw($raw);
        if (null === $pair) {
            return null;
        }

        return self::refineMonotonicNanoseconds($pair);
    }

    /**
     * @param array{0: int, 1: int} $pair seconds and nanoseconds from /proc/uptime
     *
     * @return array{0: int, 1: int}
     */
    public static function refineMonotonicNanoseconds(array $pair): array
    {
        [$sec, $nsec] = $pair;
        if (!\function_exists('microtime')) {
            return [$sec, $nsec];
        }
        $micro = \microtime(true);
        $microNsec = (int) \round(fmod($micro, 1.0) * (float) self::NS_PER_SEC);
        $nsec = ($nsec & ~999) + ($microNsec % 1000);
        if ($nsec >= self::NS_PER_SEC) {
            ++$sec;
            $nsec -= self::NS_PER_SEC;
        }

        return [$sec, $nsec];
    }
}
