--TEST--
stdlib DatePeriod::getEndDate() — null for recurrence count, clone for end-date form (#17495, ext/date/php_date.c)
--FILE--
<?php
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);
var_export(method_exists($period, 'getEndDate'));
echo "\n";
var_export($period->getEndDate());
echo "\n";
$end = new DateTime('2020-01-05');
$periodEnd = new DatePeriod($start, $interval, $end);
echo $periodEnd->getEndDate()->format('Y-m-d'), "\n";
--EXPECT--
true
NULL
2020-01-05
