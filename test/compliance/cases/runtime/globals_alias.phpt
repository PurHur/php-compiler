--TEST--
$GLOBALS aliases top-level globals on VM and JIT (issue #4423)
--FILE--
<?php
$x = 1;
$GLOBALS['x'] = 2;
echo $x, "\n";

$y = 10;
echo isset($GLOBALS['y']) ? "1\n" : "0\n";

$GLOBALS['z'] = 123;
echo $z, "\n";
--EXPECT--
2
1
123
