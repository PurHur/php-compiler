--TEST--
stdlib array_keys() nested array-returning producer — flip/slice/values (#21981, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_keys(array_flip(['a', 'b'])));
echo "\n";
var_export(array_keys(array_values(['x' => 1, 'y' => 2])));
echo "\n";
var_export(array_keys(array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 1, true)));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 0,
  1 => 1,
)
array (
  0 => 'b',
)
