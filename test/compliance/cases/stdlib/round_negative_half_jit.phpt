--TEST--
JIT round() negative half-integers — half away from zero (#16903, ext/standard/math.c)
--FILE--
<?php
echo round(-0.5), "\n";
echo round(-1.5), "\n";
echo round(0.5), "\n";
?>
--EXPECT--
-1
-2
1
