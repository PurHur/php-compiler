--TEST--
stdlib time_sleep_until() past timestamp emits Warning (issue #12889, ext/standard/basic_functions.c)
--FILE--
<?php
error_reporting(E_ALL);
$past = (float) (time() - 1);
$ok = time_sleep_until($past);
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";
--EXPECTF--
PHP Warning:  time_sleep_until(): Argument #1 ($timestamp) must be greater than or equal to the current time in - on line %d
false
'time_sleep_until(): Argument #1 ($timestamp) must be greater than or equal to the current time'
