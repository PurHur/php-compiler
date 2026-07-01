--TEST--
stdlib DatePeriod constructor overloads + foreach (#14228, ext/date/php_date.c)
--FILE--
<?php
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$end = new DateTime('2020-01-05');
$periodEnd = new DatePeriod($start, $interval, $end);
$outEnd = '';
foreach ($periodEnd as $d) {
    $outEnd .= $d->format('Y-m-d').' ';
}
echo $outEnd, "\n";
$periodCount = new DatePeriod($start, $interval, 3);
$outCount = '';
foreach ($periodCount as $d) {
    $outCount .= $d->format('d');
}
echo $outCount, "\n";
--EXPECT--
2020-01-01 2020-01-02 2020-01-03 2020-01-04 
01020304
