--TEST--
stdlib time_nanosleep() and time_sleep_until() with near-zero delay
--FILE--
<?php
echo function_exists('time_nanosleep') ? "nanosleep-exists\n" : "nanosleep-missing\n";
echo function_exists('time_sleep_until') ? "sleep-until-exists\n" : "sleep-until-missing\n";
$r = time_nanosleep(0, 10_000_000);
echo true === $r ? "nanosleep-ok\n" : "nanosleep-fail\n";
$past = (float) (time() - 1);
$r2 = @time_sleep_until($past);
echo false === $r2 ? "sleep-until-past-false\n" : "sleep-until-past-fail\n";
--EXPECT--
nanosleep-exists
sleep-until-exists
nanosleep-ok
sleep-until-past-false
