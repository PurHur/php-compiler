<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sleep/usleep/time_nanosleep/time_sleep_until — pure PHP default (#8922, #8971).
 *
 * Delegates to {@see VmSleepPure} (VmHrtimeNative monotonic poll). No libc sleep/usleep/nanosleep FFI
 * on the default path — shrinks native link surface for self-host/M5 (#1492).
 *
 * Mirrors {@see SleepJitHelper} via {@see \PHPCompiler\JIT\Builtin\TimeSleepRuntime} and {@see JitSleep} — no host
 * \\sleep()/\\usleep()/\\time_nanosleep() delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sleep), usleep, time_nanosleep, time_sleep_until
 */
final class VmSleepNative
{
    public static function available(): bool
    {
        return true;
    }

    public static function sleep(int $seconds): int|false
    {
        return VmSleepPure::sleep($seconds);
    }

    public static function usleep(int $microseconds): void
    {
        VmSleepPure::usleep($microseconds);
    }

    /** @return true|array{seconds: int, nanoseconds: int}|false */
    public static function timeNanosleep(int $seconds, int $nanoseconds): mixed
    {
        return VmSleepPure::timeNanosleep($seconds, $nanoseconds);
    }

    public static function timeSleepUntil(float $timestamp): bool
    {
        return VmSleepPure::timeSleepUntil($timestamp);
    }
}
