<?php
// Repro for #20779 — php-src DateMalformed*StringException names (not phantom #7129 names).
echo 'DateMalformedIntervalStringException: ', class_exists('DateMalformedIntervalStringException', false) ? 'yes' : 'no', "\n";
echo 'DateMalformedPeriodStringException: ', class_exists('DateMalformedPeriodStringException', false) ? 'yes' : 'no', "\n";
echo 'phantom IntervalException: ', class_exists('DateMalformedIntervalException', false) ? 'yes' : 'no', "\n";
echo 'phantom PeriodException: ', class_exists('DateMalformedPeriodException', false) ? 'yes' : 'no', "\n";
echo 'interval sub DateException: ', is_subclass_of('DateMalformedIntervalStringException', 'DateException') ? 'yes' : 'no', "\n";
echo 'period sub DateException: ', is_subclass_of('DateMalformedPeriodStringException', 'DateException') ? 'yes' : 'no', "\n";
try {
    throw new DateMalformedIntervalStringException('interval test');
} catch (DateException $e) {
    echo 'caught interval: ', $e->getMessage(), "\n";
}
try {
    throw new DateMalformedPeriodStringException('period test');
} catch (DateException $e) {
    echo 'caught period: ', $e->getMessage(), "\n";
}
