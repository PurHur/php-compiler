--TEST--
stdlib array_pad() negative length prepend (#10907, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
echo var_export(array_pad([0, 1], -4, 0), true), "\n";
echo var_export(array_pad([1, 2, 3], -5, 0), true), "\n";
echo var_export(array_pad([], -3, 'x'), true), "\n";
?>
--EXPECT--
array (
  0 => 0,
  1 => 0,
  2 => 0,
  3 => 1,
)
array (
  0 => 0,
  1 => 0,
  2 => 1,
  3 => 2,
  4 => 3,
)
array (
  0 => 'x',
  1 => 'x',
  2 => 'x',
)
