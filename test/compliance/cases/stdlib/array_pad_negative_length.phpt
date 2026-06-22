--TEST--
stdlib array_pad() negative $length prepends (#10351, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(array_pad([1], -3, 0), true), "\n";
echo var_export(array_pad(array(1, 2), -4, 0), true), "\n";
--EXPECT--
array (
  0 => 0,
  1 => 0,
  2 => 1,
)
array (
  0 => 0,
  1 => 0,
  2 => 1,
  3 => 2,
)
