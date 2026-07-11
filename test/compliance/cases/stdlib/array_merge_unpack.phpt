--TEST--
Stdlib: array_merge() call-time unpack passes array operands (#17532, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_merge(...[[1, 2], [3]]));
echo "\n";
var_export(array_merge(...[['a' => 1], ['b' => 2]]));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  'a' => 1,
  'b' => 2,
)
