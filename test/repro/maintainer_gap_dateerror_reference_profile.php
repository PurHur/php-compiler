<?php

declare(strict_types=1);

// Repro for #13118 — DateException/DateError hierarchy absent on Zend 8.2 reference profile.

$checks = [
    'DateException',
    'DateInvalidTimeZoneException',
    'DateMalformedIntervalException',
    'DateMalformedPeriodException',
    'DateError',
    'DateObjectError',
    'DateRangeError',
];

foreach ($checks as $class) {
    if (class_exists($class, false)) {
        echo "fail: {$class} registered on Zend 8.2 reference profile\n";
        exit(1);
    }
}

echo "ok\n";
