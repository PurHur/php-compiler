--TEST--
stdlib array_fill_keys() — nested array key warns and uses "Array" (#10848, ext/standard/array.c)
--FILE--
<?php
var_export(@array_fill_keys([[[1]]], 1));
echo "\n";
var_export(@array_fill_keys([[1]], 1));
echo "\n";
--EXPECT--
array (
  'Array' => 1,
)
array (
  'Array' => 1,
)
