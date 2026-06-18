<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_time_nanosleep / __compiler_time_sleep_until (#9378).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(time_nanosleep), time_sleep_until
 * SSOT: ext/standard/VmSleepPure.php
 */
final class SleepJitHelper
{
    /** @return int 1 on success, 0 on failure (LLVM i32 ABI) */
    public static function timeNanosleep(int $seconds, int $nanoseconds): int
    {
        $result = VmSleepPure::timeNanosleep($seconds, $nanoseconds);

        return true === $result ? 1 : 0;
    }

    /** @return int 1 on success, 0 on failure (LLVM i32 ABI) */
    public static function timeSleepUntil(float $timestamp): int
    {
        return VmSleepPure::timeSleepUntil($timestamp) ? 1 : 0;
    }
}
