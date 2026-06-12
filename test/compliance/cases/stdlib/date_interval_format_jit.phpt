--TEST--
stdlib date_interval_format() JIT (#7278 phase 2, ext/date/php_date.c)
--FILE--
<?php
$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
echo $interval->format('%y%m%d'), "\n";

$full = new DateInterval('P1Y2M3DT4H5M6S');
echo date_interval_format($full, '%y %m %d %h %i %s'), "\n";

try {
    date_interval_format([], '%d');
    echo "no-type-error\n";
} catch (\TypeError $e) {
    echo "type-error\n";
}
--EXPECT--
1
001
1 2 3 4 5 6
type-error
