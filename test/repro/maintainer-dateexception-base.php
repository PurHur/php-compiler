<?php
// Repro for #7277 — DateException base class (ext/date/php_date.h).

echo 'DateException: ', class_exists('DateException', false) ? 'yes' : 'no', "\n";
echo 'DateInvalidTimeZoneException sub: ', is_subclass_of('DateInvalidTimeZoneException', 'DateException') ? 'yes' : 'no', "\n";
echo 'DateMalformedIntervalException sub: ', is_subclass_of('DateMalformedIntervalException', 'DateException') ? 'yes' : 'no', "\n";
echo 'DateMalformedPeriodException sub: ', is_subclass_of('DateMalformedPeriodException', 'DateException') ? 'yes' : 'no', "\n";

try {
    throw new DateInvalidTimeZoneException('tz test');
} catch (DateException $e) {
    echo "catch DateInvalidTimeZoneException as DateException: ok\n";
}
