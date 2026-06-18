<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sleep/usleep/time_nanosleep/time_sleep_until (#4860).
 *
 * JIT/AOT: {@see JitSleep} + {@see SleepJitHelper} via {@see \PHPCompiler\JIT\Builtin\TimeSleepRuntime}.
 */
final class VmSleep
{
    public static function sleep(int $seconds): int|false
    {
        return VmSleepNative::sleep($seconds);
    }

    public static function usleep(int $microseconds): void
    {
        VmSleepNative::usleep($microseconds);
    }

    /** @return true|array{seconds: int, nanoseconds: int}|false */
    public static function timeNanosleep(int $seconds, int $nanoseconds): mixed
    {
        return VmSleepNative::timeNanosleep($seconds, $nanoseconds);
    }

    public static function timeSleepUntil(float $timestamp): bool
    {
        return VmSleepNative::timeSleepUntil($timestamp);
    }
}
