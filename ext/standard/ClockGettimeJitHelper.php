<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Lowered into JIT/AOT modules for clock_gettime() (#11624, php-in-PHP).
 *
 * php-src: ext/standard/hrtime.c
 * SSOT: ext/standard/VmHrtimeNative.php
 */
final class ClockGettimeJitHelper
{
    public static function assoc(int $clockId): ?HashTable
    {
        $pair = VmHrtimeNative::readClock($clockId);
        if (null === $pair) {
            return null;
        }

        return VmClockGettime::buildResult($pair[0], $pair[1]);
    }
}
