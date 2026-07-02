--TEST--
stdlib nextafter() — IEEE next representable float (PHP 8.4, ext/standard/math.c, #9241)
--FILE--
<?php
declare(strict_types=1);

var_export(function_exists('nextafter'));
echo "\n";
var_export(nextafter(1.0, 2.0));
echo "\n";
var_export(nextafter(1.0, 0.0));
echo "\n";
var_export(nextafter(0.0, 1.0) > 0.0);
echo "\n";
--EXPECT--
true
1.0000000000000002
0.9999999999999999
true
