--TEST--
stdlib strftime() and gmstrftime() (#3692, ext/standard/datetime.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$ts = 946684800;
echo strftime('%Y-%m-%d', $ts), "\n";
echo gmstrftime('%Y-%m-%d', $ts), "\n";
echo function_exists('strftime') ? "yes\n" : "no\n";
echo function_exists('gmstrftime') ? "yes\n" : "no\n";
--EXPECT--
2000-01-01
2000-01-01
yes
yes
