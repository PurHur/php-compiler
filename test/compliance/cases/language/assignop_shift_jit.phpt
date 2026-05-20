--TEST--
Compound assignment: integer <<= (JIT)
--FILE--
<?php
$x = 1;
$x <<= 2;
echo $x, "\n";
--EXPECT--
4
