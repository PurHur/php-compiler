<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * date_interval_format() format walk for compiled JIT/AOT modules (#9499, php-in-PHP).
 *
 * SSOT: {@see VmDateInterval::format}; VM orchestration: {@see \PHPCompiler\VM\DateIntervalSupport}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
 */
final class DateIntervalFormatJitHelper
{
    public static function formatFromScalars(
        int $y,
        int $m,
        int $d,
        int $h,
        int $i,
        int $s,
        int $fMicros,
        int $invert,
        int $daysIsInt,
        int $daysInt,
        string $format
    ): string {
        // NestedJIT float args SIGSEGV under thin AOT (#34602 / #34599); pass micros as i64.
        $days = 0 !== $daysIsInt ? $daysInt : false;

        return VmDateInterval::format(
            [
                'y' => $y,
                'm' => $m,
                'd' => $d,
                'h' => $h,
                'i' => $i,
                's' => $s,
                'f' => $fMicros / 1000000.0,
                'invert' => $invert,
                'days' => $days,
            ],
            $format
        );
    }
}
