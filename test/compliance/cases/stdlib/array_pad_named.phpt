--TEST--
stdlib array_pad() array:/length:/value: named parameters (#11147, ext/standard/array.c)
--FILE--
<?php
var_export(array_pad(array: [1], length: 3, value: 0));
echo "\n";
var_export(array_pad([1], length: 3, value: 0));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 0,
  2 => 0,
)
array (
  0 => 1,
  1 => 0,
  2 => 0,
)
