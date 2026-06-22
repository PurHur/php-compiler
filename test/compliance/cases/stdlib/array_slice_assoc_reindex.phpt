--TEST--
stdlib array_slice() preserve_keys=false keeps string keys (#10600, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
var_export(array_slice($a, 1, 2, false));
echo "\n";
$m = [0 => 'a', 'x' => 'b', 1 => 'c'];
var_export(array_slice($m, 1, 2, false));
echo "\n";
--EXPECT--
array (
  'b' => 2,
  'c' => 3,
)
array (
  'x' => 'b',
  0 => 'c',
)
