<?php
// Repro for #7277 / #20779 — DateException hierarchy roots (php-src names).
echo 'DateMalformedIntervalStringException sub: ', is_subclass_of('DateMalformedIntervalStringException', 'DateException') ? 'yes' : 'no', "\n";
echo 'DateMalformedPeriodStringException sub: ', is_subclass_of('DateMalformedPeriodStringException', 'DateException') ? 'yes' : 'no', "\n";
echo 'phantom IntervalException: ', class_exists('DateMalformedIntervalException', false) ? 'yes' : 'no', "\n";
echo 'phantom PeriodException: ', class_exists('DateMalformedPeriodException', false) ? 'yes' : 'no', "\n";
