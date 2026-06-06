--TEST--
AOT idate() — fixed timestamp parts (#6830)
--FILE--
<?php
$ts = 946684800;
echo idate('Y', $ts), "\n";
echo idate('m', $ts), "\n";
echo idate('d', $ts), "\n";
echo idate('w', $ts), "\n";
echo idate('U', $ts), "\n";
$prev = error_reporting(0);
var_export(idate('bad', $ts));
error_reporting($prev);
echo "\n";
--EXPECT--
2000
1
1
6
946684800
false
