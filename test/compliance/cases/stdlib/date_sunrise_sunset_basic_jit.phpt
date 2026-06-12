--TEST--
stdlib date_sunrise()/date_sunset() JIT/AOT — compile-time baked literals (#6137)
--FILE--
<?php
$rise = date_sunrise(1782000000, 0, 51.5, -0.1, 90.0, 0.0);
$set = date_sunset(1782000000, 0, 51.5, -0.1, 90.0, 0.0);
echo is_int($rise) ? "rise_int\n" : "rise_bad\n";
echo is_int($set) ? "set_int\n" : "set_bad\n";
echo $rise, "\n";
echo $set, "\n";
echo date_sunrise(1782000000, 1, 51.5, -0.1, 90.0, 0.0), "\n";
--EXPECT--
rise_int
set_int
1782013676
1782072990
03:47
