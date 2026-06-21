--TEST--
stdlib array_slice() negative length and preserve_keys negative offset (#10229, ext/standard/array.c)
--FILE--
<?php
$a = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
var_export(array_slice($a, -2, 2, true));
echo "\n";
var_export(array_slice(['a', 'b', 'c', 'd', 'e'], 1, -2));
echo "\n";
--EXPECT--
array (
  2 => 'c',
  3 => 'd',
)
array (
  0 => 'b',
  1 => 'c',
)
