--TEST--
stdlib gmgetdate() — fixed UTC timestamp breakdown
--FILE--
<?php
echo function_exists('gmgetdate') ? '1' : '0';
echo function_exists('gmmktime') ? '1' : '0';
echo "\n";
$d = gmgetdate(946684800);
echo $d['year'], '-', $d['mon'], '-', $d['mday'], "\n";
echo $d['hours'], ':', $d['minutes'], ':', $d['seconds'], "\n";
echo $d['wday'], ' ', $d['weekday'], ' ', $d['month'], "\n";
echo $d['yday'], ' ', $d[0], "\n";
echo gmmktime(22, 13, 20, 11, 14, 2023), "\n";
--EXPECT--
11
2000-1-1
0:0:0
6 Saturday January
0 946684800
1700000000
