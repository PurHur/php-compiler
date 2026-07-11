--TEST--
stdlib DateTime/DateInterval survive instanceof assign + echo (#11867)
--FILE--
<?php
$dt = new DateTime('2020-01-01');
$is = $dt instanceof DateTime;
echo $is ? 'dt_ok' : 'dt_no', "\n";
echo $dt->format('Y-m-d'), "\n";
$di = DateInterval::createFromDateString('1 day');
$diIs = $di instanceof DateInterval;
echo $diIs ? 'di_ok' : 'di_no', "\n";
echo $di->d, "\n";
--EXPECT--
dt_ok
2020-01-01
di_ok
1
