<?php
// Repro for #7129 — DateMalformedIntervalException / DateMalformedPeriodException (ext/date/php_date.h).

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
