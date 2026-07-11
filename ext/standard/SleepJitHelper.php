<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for sleep/usleep and __compiler_time_* (#9378, #15212).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sleep), usleep, time_nanosleep, time_sleep_until
 * SSOT: ext/standard/VmSleepPure.php
 */
final class SleepJitHelper
{
    public static function sleepArgv(int $seconds): int
    {
        $result = VmSleepPure::sleep($seconds);

        return false === $result ? 0 : $result;
    }

    public static function usleepArgv(int $microseconds): void
    {
        VmSleepPure::usleep($microseconds);
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for __compiler_time_* */
    public static function timeNanosleep(int $seconds, int $nanoseconds): bool
    {
        $result = VmSleepPure::timeNanosleep($seconds, $nanoseconds);

        return true === $result;
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for __compiler_time_* */
    public static function timeSleepUntil(float $timestamp): bool
    {
        if (VmSleepPure::isTimestampInPast($timestamp)) {
            TriggerErrorJitHelper::warning(VmSleepPure::PAST_TIMESTAMP_WARNING);

            return false;
        }

        return VmSleepPure::timeSleepUntil($timestamp);
    }
}
