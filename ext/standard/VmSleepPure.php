<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sleep/usleep/time_nanosleep/time_sleep_until without libc FFI (#8922).
 *
 * Zero-delay calls succeed without a clock. Non-zero delays poll
 * {@see VmHrtimeNative} monotonic time (Linux: /proc/uptime via VmFsReadNative).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sleep), usleep, time_nanosleep, time_sleep_until
 */
final class VmSleepPure
{
    private const NS_PER_SEC = 1_000_000_000;

    public const PAST_TIMESTAMP_WARNING = 'time_sleep_until(): Argument #1 ($timestamp) must be greater than or equal to the current time';

    public static function sleep(int $seconds): int|false
    {
        if ($seconds < 0) {
            throw new \ValueError('sleep(): Argument #1 ($seconds) must be greater than or equal to 0');
        }
        if (0 === $seconds) {
            return 0;
        }
        if (!self::monotonicAvailable()) {
            return false;
        }
        self::delayNanos($seconds * self::NS_PER_SEC);

        return 0;
    }

    public static function usleep(int $microseconds): void
    {
        if ($microseconds < 0) {
            throw new \ValueError('usleep(): Argument #1 ($microseconds) must be greater than or equal to 0');
        }
        if (0 === $microseconds) {
            return;
        }
        if (!self::monotonicAvailable()) {
            return;
        }
        self::delayNanos($microseconds * 1000);
    }

    /** @return true|array{seconds: int, nanoseconds: int}|false */
    public static function timeNanosleep(int $seconds, int $nanoseconds): mixed
    {
        if ($seconds < 0) {
            throw new \ValueError('time_nanosleep(): Argument #1 ($seconds) must be greater than or equal to 0');
        }
        if ($nanoseconds < 0) {
            throw new \ValueError('time_nanosleep(): Argument #2 ($nanoseconds) must be greater than or equal to 0');
        }
        if (0 === $seconds && 0 === $nanoseconds) {
            return true;
        }
        if (!self::monotonicAvailable()) {
            return false;
        }
        $totalNs = $seconds * self::NS_PER_SEC + $nanoseconds;
        self::delayNanos($totalNs);

        return true;
    }

    public static function timeSleepUntil(float $timestamp): bool
    {
        if (self::isTimestampInPast($timestamp)) {
            return false;
        }
        $tv = VmDate::wallClock();
        $currentNs = (float) $tv['sec'] * (float) self::NS_PER_SEC + (float) $tv['usec'] * 1000.0;
        if (0.0 === $currentNs && 0.0 === $tv['usec']) {
            return false;
        }
        $targetNs = $timestamp * (float) self::NS_PER_SEC;
        $diffNs = (int) \round($targetNs - $currentNs);
        if (0 === $diffNs) {
            return true;
        }
        if (!self::monotonicAvailable()) {
            return false;
        }
        self::delayNanos($diffNs);

        return true;
    }

    public static function isTimestampInPast(float $timestamp): bool
    {
        $tv = VmDate::wallClock();
        $currentNs = (float) $tv['sec'] * (float) self::NS_PER_SEC + (float) $tv['usec'] * 1000.0;
        if (0.0 === $currentNs && 0.0 === $tv['usec']) {
            return false;
        }
        $targetNs = $timestamp * (float) self::NS_PER_SEC;

        return $targetNs < $currentNs;
    }

    private static function monotonicAvailable(): bool
    {
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();

        return 0 !== $sec || 0 !== $nsec;
    }

    private static function delayNanos(int $durationNs): void
    {
        if ($durationNs <= 0) {
            return;
        }
        $start = VmHrtime::hrtime(true);
        $target = $start + $durationNs;
        while (VmHrtime::hrtime(true) < $target) {
        }
    }
}
