--TEST--
list destructuring skip slots consume source indices without binding
--FILE--
<?php
[$a, , $c] = [10, 20, 30];
echo "$a,$c\n";

[[$x, $y], , $z] = [[1, 2], [3, 4], 5];
var_export([$x, $y, $z]);
echo "\n";
--EXPECT--
10,30
array (
  0 => 1,
  1 => 2,
  2 => 5,
)
