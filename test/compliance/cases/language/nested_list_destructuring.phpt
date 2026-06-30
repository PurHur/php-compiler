--TEST--
nested list destructuring assigns inner elements (#13932)
--FILE--
<?php
[$a, [$b, $c]] = [1, [2, 3]];
echo $a . $b . $c;
echo "\n";
[[$x, $y], $z] = [[1, 2], 3];
var_export([$x, $y, $z]);
echo "\n";
--EXPECT--
123
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
