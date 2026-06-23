--TEST--
stdlib array_pad() preserves associative keys when shrinking (#10777, ext/standard/array.c)
--FILE--
<?php
echo var_export(array_pad(['a' => 1, 'b' => 2], 2, 0), true), "\n";
echo var_export(array_pad(['a' => 1, 'b' => 2], 5, 0), true), "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 1,
  'b' => 2,
  0 => 0,
  1 => 0,
  2 => 0,
)
