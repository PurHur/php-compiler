--TEST--
stdlib date_interval_format() / DateInterval::format() (#7278, ext/date/php_date.c)
--FILE--
<?php
echo function_exists('date_interval_format') ? "fn\n" : "no-fn\n";
echo class_exists('DateInterval', false) ? "class\n" : "no-class\n";

$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
echo $interval->format('%y%m%d'), "\n";

$full = new DateInterval('P1Y2M3DT4H5M6S');
echo $full->format('%y %m %d %h %i %s'), "\n";

try {
    date_interval_format([], '%d');
    echo "no-type-error\n";
} catch (\TypeError $e) {
    echo "type-error\n";
}
--EXPECT--
fn
class
1
001
1 2 3 4 5 6
type-error
