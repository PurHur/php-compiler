--TEST--
stdlib DatePeriod::getEndDate() — recurrence null, end-date clone (#17495, ext/date/php_date.c)
--FILE--
<?php
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$recurrence = new DatePeriod($start, $interval, 3);
echo method_exists($recurrence, 'getEndDate') ? "method-yes\n" : "method-no\n";
var_export($recurrence->getEndDate());
echo "\n";
$end = new DateTime('2020-01-05');
$bounded = new DatePeriod($start, $interval, $end);
echo $bounded->getEndDate()->format('Y-m-d'), "\n";
--EXPECT--
method-yes
NULL
2020-01-05
