--TEST--
JIT: time_nanosleep() and time_sleep_until() with near-zero delay
--FILE--
<?php
$r = time_nanosleep(0, 10_000_000);
echo true === $r ? "nanosleep-ok\n" : "nanosleep-fail\n";
$past = (float) (time() - 1);
$r2 = time_sleep_until($past);
echo false === $r2 ? "sleep-until-past-false\n" : "sleep-until-past-fail\n";
--EXPECTF--
PHP Warning:  time_sleep_until(): Argument #1 ($timestamp) must be greater than or equal to the current time in - on line %d
nanosleep-ok
sleep-until-past-false
