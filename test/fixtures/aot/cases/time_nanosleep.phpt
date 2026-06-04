--TEST--
AOT: time_nanosleep() and time_sleep_until() with near-zero delay (issue #5180)
--FILE--
<?php
echo function_exists('time_nanosleep') ? '1' : '0', "\n";
echo function_exists('time_sleep_until') ? '1' : '0', "\n";
$r = time_nanosleep(0, 10000000);
echo true === $r ? '1' : '0', "\n";
$past = time() - 1;
$r2 = time_sleep_until($past);
echo false === $r2 ? '1' : '0', "\n";
--EXPECT--
1
1
1
1
