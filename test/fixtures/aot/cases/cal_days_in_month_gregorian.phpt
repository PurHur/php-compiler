--TEST--
cal_days_in_month() AOT Gregorian leap February (#27310)
--FILE--
<?php
echo cal_days_in_month(CAL_GREGORIAN, 2, 2024), "\n";
echo cal_days_in_month(CAL_GREGORIAN, 2, 2023), "\n";
$month = 2;
$year = 2024;
echo cal_days_in_month(CAL_GREGORIAN, $month, $year), "\n";
--EXPECT--
29
28
29
