--TEST--
stdlib localtime() — numeric and associative breakdown
--FILE--
<?php
$t = 1700000000;
$n = localtime($t);
$a = localtime($t, true);
echo $n[0], ' ', $n[1], ' ', $n[2], ' ', $n[3], ' ', $n[4], ' ', $n[5], ' ', $n[6], ' ', $n[7], ' ', $n[8], "\n";
echo $a['tm_sec'], ' ', $a['tm_min'], ' ', $a['tm_hour'], ' ', $a['tm_mday'], ' ', $a['tm_mon'], ' ', $a['tm_year'], ' ', $a['tm_wday'], ' ', $a['tm_yday'], ' ', $a['tm_isdst'], "\n";
echo function_exists('localtime') ? "yes\n" : "no\n";
--EXPECT--
20 13 22 14 10 123 2 317 0
20 13 22 14 10 123 2 317 0
yes
