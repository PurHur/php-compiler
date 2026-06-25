--TEST--
stdlib array_walk() / array_walk_recursive() — arrow closure by-ref (#11532, ext/standard/array.c)
--FILE--
<?php
$a = [1, 2];
array_walk($a, fn (&$v) => $v++);
var_export($a);
echo "\n";

$b = [1 => [2]];
array_walk_recursive($b, fn (&$v) => $v++);
var_export($b);
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 3,
)
array (
  1 => 
  array (
    0 => 3,
  ),
)
