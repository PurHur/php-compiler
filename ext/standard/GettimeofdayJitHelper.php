<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * gettimeofday() for compiled JIT/AOT modules (#13764, php-in-PHP).
 *
 * SSOT: {@see VmDate::gettimeofdayArray()} / {@see VmDate::gettimeofdayFloat()} / {@see VmDate::wallClock()}
 * php-src: ext/standard/microtimers.c — PHP_FUNCTION(gettimeofday)
 */
final class GettimeofdayJitHelper
{
    public static function gettimeofdayFloat(): float
    {
        return VmDate::gettimeofdayFloat();
    }

    public static function gettimeofdayArray(): HashTable
    {
        return VmDate::gettimeofdayArray();
    }

    public static function wallClockSec(): int
    {
        return VmDate::wallClock()['sec'];
    }

    public static function wallClockUsecMasked(int $usecMod): int
    {
        $usec = VmDate::wallClock()['usec'];
        if ($usecMod <= 0) {
            return $usec;
        }

        return $usec % $usecMod;
    }
}
