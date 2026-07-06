<?php

declare(strict_types=1);

// Maintainer gap #16888 — DateMalformed* phantom classes withheld on 8.2 reference profile.

$fail = 0;

foreach (['DateMalformedIntervalException', 'DateMalformedString', 'DateMalformedPeriodException'] as $class) {
    if (class_exists($class, false)) {
        fwrite(STDERR, "FAIL: class_exists({$class}) true on reference profile\n");
        ++$fail;
    }
}

if (0 !== $fail) {
    exit(1);
}

echo "ok\n";
