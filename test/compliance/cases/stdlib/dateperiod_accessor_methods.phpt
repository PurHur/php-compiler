--TEST--
stdlib DatePeriod::getStartDate()/getDateInterval()/getRecurrences()/getEndDate() (#16614, #17495, ext/date/php_date.c)
--FILE--
<?php
$start = new DateTime('2020-01-01');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, 3);
echo $period->getStartDate()->format('Y-m-d'), "\n";
echo $period->getDateInterval()->format('P1D'), "\n";
var_export($period->getRecurrences());
echo "\n";
var_export($period->getEndDate());
echo "\n";
$end = new DateTime('2020-01-05');
$periodEnd = new DatePeriod($start, $interval, $end);
echo $periodEnd->getStartDate()->format('Y-m-d'), "\n";
var_export($periodEnd->getRecurrences());
echo "\n";
echo $periodEnd->getEndDate()->format('Y-m-d'), "\n";
--EXPECT--
2020-01-01
P1D
3
NULL
2020-01-01
NULL
2020-01-05
