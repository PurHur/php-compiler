--TEST--
cal_info() AOT Gregorian months (#27354)
--FILE--
<?php
$i = cal_info(CAL_GREGORIAN);
echo $i["months"][2], "\n";
echo $i["calname"], "\n";
$cal = CAL_GREGORIAN;
$j = cal_info($cal);
echo $j["months"][2], "\n";
--EXPECT--
February
Gregorian
February
