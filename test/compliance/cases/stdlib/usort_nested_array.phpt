--TEST--
stdlib usort() closure on nested array elements (#10212, ext/standard/array.c)
--FILE--
<?php
$a = [['b', 1], ['a', 1]];
usort($a, fn ($x, $y) => strcmp($x[0], $y[0]));
var_export($a);
echo "\n";
$b = [['a', 1], ['b', 1]];
usort($b, fn ($x, $y) => $x[1] <=> $y[1] ?: strcmp($x[0], $y[0]));
var_export($b);
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 'a',
    1 => 1,
  ),
  1 => array (
    0 => 'b',
    1 => 1,
  ),
)
array (
  0 => array (
    0 => 'a',
    1 => 1,
  ),
  1 => array (
    0 => 'b',
    1 => 1,
  ),
)
