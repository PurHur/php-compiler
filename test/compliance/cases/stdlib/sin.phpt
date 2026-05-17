--TEST--
stdlib sin()
--FILE--
<?php
echo sin(0), "\n";
echo intval(sin(deg2rad(90)) * 1000), "\n";
echo intval(sin(deg2rad(30)) * 1000), "\n";
--EXPECT--
0
1000
499
