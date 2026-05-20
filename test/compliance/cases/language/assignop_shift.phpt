--TEST--
Compound assignment: integer <<= (VM)
--FILE--
<?php
$x = 1;
$x <<= 2;
echo $x, "\n";
--EXPECT--
4
