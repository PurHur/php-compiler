--TEST--
stdlib abs() clears signed zero (php-src math.c fabs; #23978)
--FILE--
<?php
var_export(abs(-0.0));
echo "\n";
echo json_encode(abs(-0.0)), "\n";
var_export(abs(0.0));
echo "\n";
--EXPECT--
0.0
0
0.0
