--TEST--
date DateMalformedIntervalStringException on forward profile (#20779, ext/date/php_date.stub.php)
--FILE--
<?php
echo 'StringException: ', class_exists('DateMalformedIntervalStringException', false) ? 'yes' : 'no', "\n";
echo 'IntervalException: ', class_exists('DateMalformedIntervalException', false) ? 'yes' : 'no', "\n";
echo 'PeriodException: ', class_exists('DateMalformedPeriodException', false) ? 'yes' : 'no', "\n";
echo 'PeriodStringException: ', class_exists('DateMalformedPeriodStringException', false) ? 'yes' : 'no', "\n";
try {
    new DateInterval('garbage');
    echo "no throw\n";
} catch (DateMalformedIntervalStringException $e) {
    echo 'throw: ', get_class($e), "\n";
    echo 'instanceof DateException: ', $e instanceof DateException ? 'yes' : 'no', "\n";
} catch (Throwable $e) {
    echo 'wrong: ', get_class($e), "\n";
}
--EXPECT--
StringException: yes
IntervalException: no
PeriodException: no
PeriodStringException: yes
throw: DateMalformedIntervalStringException
instanceof DateException: yes
