--TEST--
stdlib sleep()/usleep()/time_nanosleep() JIT — null coerces on 8.4 forward profile (#19077)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo sleep(null), "\n";
var_export(@usleep(null));
echo "\n";
var_export(time_nanosleep(null, 0));
echo "\n";
--EXPECT--
0
NULL
true
