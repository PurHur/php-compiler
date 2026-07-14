--TEST--
date strftime(null)/gmstrftime(null) return false not empty string (#18945, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
var_export(strftime(null));
echo "\n";
var_export(gmstrftime(null));
echo "\n";
$ts = 946684800;
echo strftime('%Y-%m-%d', $ts), "\n";
echo gmstrftime('%Y-%m-%d', $ts), "\n";
--EXPECT--
false
false
2000-01-01
2000-01-01
