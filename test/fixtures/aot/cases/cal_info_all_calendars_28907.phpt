--TEST--
cal_info(-1) AOT all-calendars sentinel (#28907)
--FILE--
<?php
$all = cal_info(-1);
echo count($all), "\n";
echo $all[0]["calname"], "\n";
$cal = -1;
$dyn = cal_info($cal);
echo count($dyn), "\n";
echo $dyn[1]["calname"], "\n";
--EXPECT--
4
Gregorian
4
Julian
