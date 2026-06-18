<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hrtime() for VM without host Zend \hrtime() (issue #5174, #3195, #7315).
 *
 * php-src: ext/standard/hrtime.c — clock_gettime(CLOCK_MONOTONIC).
 * JIT/AOT: ext/standard/HrtimeJitHelper.php via StringHrtimeRuntime (#9182).
 */
final class VmHrtime
{
    private const NS_PER_SEC = 1_000_000_000;

    /**
     * @return int|array{0: int, 1: int}
     */
    public static function hrtime(bool $asNumber = false)
    {
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();
        if ($asNumber) {
            return (int) ($sec * self::NS_PER_SEC + $nsec);
        }

        return [$sec, $nsec];
    }
}
