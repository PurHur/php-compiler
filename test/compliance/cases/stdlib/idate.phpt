--TEST--
stdlib idate() — fixed timestamp parts (UTC harness, #6830)
--FILE--
<?php
$ts = 946684800;
echo idate('Y', $ts), "\n";
echo idate('m', $ts), "\n";
echo idate('d', $ts), "\n";
echo idate('w', $ts), "\n";
echo idate('U', $ts), "\n";
echo idate('z', $ts), "\n";
$prev = error_reporting(0);
var_export(idate('YY', $ts));
echo "\n";
var_export(idate('X', $ts));
error_reporting($prev);
echo "\n";
--EXPECT--
2000
1
1
6
946684800
0
false
false
