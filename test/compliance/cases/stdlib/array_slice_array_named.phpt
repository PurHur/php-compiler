--TEST--
stdlib array_slice() array:/offset:/length: named parameters (#11145, ext/standard/array.c)
--FILE--
<?php
var_export(array_slice(array: [1, 2, 3, 4], offset: 1, length: 2));
echo "\n";
var_export(array_slice(array: [1, 2, 3], offset: 1, preserve_keys: true));
echo "\n";
--EXPECT--
array (
  0 => 2,
  1 => 3,
)
array (
  1 => 2,
  2 => 3,
)
