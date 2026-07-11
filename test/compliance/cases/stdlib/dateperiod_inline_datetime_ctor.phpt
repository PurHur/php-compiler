--TEST--
stdlib DatePeriod inline DateTime ctor args + foreach (#15124, ext/date/php_date.c)
--FILE--
<?php
$count = 0;
foreach (new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03')) as $d) {
    $count++;
}
echo 'count=', $count, "\n";
echo 'ic=', iterator_count(new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 3)), "\n";
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
$varCount = 0;
foreach (new DatePeriod($s, new DateInterval('P1D'), $e) as $d) {
    $varCount++;
}
echo 'var=', $varCount, "\n";
--EXPECT--
count=2
ic=4
var=2
