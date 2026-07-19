<?php

declare(strict_types=1);

/**
 * Maintainer gap repro for #20779 — DateMalformedIntervalStringException (php-src-strict).
 * Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_date_malformed_interval_string_exception.php
 */

echo 'StringException: ' . (class_exists('DateMalformedIntervalStringException') ? 'yes' : 'no') . "\n";
echo 'IntervalException: ' . (class_exists('DateMalformedIntervalException') ? 'yes' : 'no') . "\n";
echo 'PeriodException: ' . (class_exists('DateMalformedPeriodException') ? 'yes' : 'no') . "\n";
echo 'PeriodStringException: ' . (class_exists('DateMalformedPeriodStringException') ? 'yes' : 'no') . "\n";
try {
    new DateInterval('garbage');
    echo "no throw\n";
} catch (Throwable $e) {
    echo 'throw: ' . get_class($e) . "\n";
    echo 'instanceof DateException: ' . ($e instanceof DateException ? 'yes' : 'no') . "\n";
}
