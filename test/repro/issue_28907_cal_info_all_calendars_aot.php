<?php
// AOT-safe repro #28907 — cal_info(-1) all-calendars (no Reflection / no !==)
$all = cal_info(-1);
echo count($all), "\n";
echo $all[0]["calname"], "\n";
echo $all[1]["calname"], "\n";
echo $all[2]["calname"], "\n";
echo $all[3]["calname"], "\n";
$cal = -1;
$dyn = cal_info($cal);
echo count($dyn), "\n";
echo $dyn[1]["calname"], "\n";
