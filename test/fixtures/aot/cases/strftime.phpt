--TEST--
AOT strftime() and gmstrftime() (#3692)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$ts = 946684800;
echo strftime('%Y-%m-%d', $ts), "\n";
echo gmstrftime('%Y-%m-%d', $ts), "\n";
--EXPECT--
2000-01-01
2000-01-01
