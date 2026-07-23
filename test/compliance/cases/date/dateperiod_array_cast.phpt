--TEST--
date (array) cast DatePeriod includes null current/end (#22435, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$dp = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
$a = (array) $dp;
echo array_key_exists('current', $a) && null === $a['current'] ? "current=null\n" : "current=missing\n";
echo array_key_exists('end', $a) && null === $a['end'] ? "end=null\n" : "end=missing\n";
echo implode(',', array_keys($a)), "\n";
?>
--EXPECT--
current=null
end=null
start,current,end,interval,recurrences,include_start_date,include_end_date
