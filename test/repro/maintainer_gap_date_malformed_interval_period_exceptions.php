<?php

declare(strict_types=1);

// Repro for #15382 — DateMalformedIntervalException / DateMalformedPeriodException (ext/date/php_date.h).

echo 'DateMalformedIntervalException: ', class_exists('DateMalformedIntervalException', false) ? 'yes' : 'no', "\n";
echo 'DateMalformedPeriodException: ', class_exists('DateMalformedPeriodException', false) ? 'yes' : 'no', "\n";
echo 'interval sub DateException: ', is_subclass_of('DateMalformedIntervalException', 'DateException') ? 'yes' : 'no', "\n";
echo 'period sub DateException: ', is_subclass_of('DateMalformedPeriodException', 'DateException') ? 'yes' : 'no', "\n";

try {
    throw new DateMalformedIntervalException('interval test');
} catch (DateException $e) {
    echo "catch interval DateException: ok\n";
}

try {
    throw new DateMalformedPeriodException('period test');
} catch (DateException $e) {
    echo "catch period DateException: ok\n";
}

try {
    new DateInterval('bad');
    echo "interval ctor: no throw\n";
    exit(1);
} catch (DateMalformedIntervalException $e) {
    echo $e->getMessage(), "\n";
}
