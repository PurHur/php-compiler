--TEST--
stdlib usort() string-keyed array reindexes to packed list (#6801, ext/standard/array.c)
--FILE--
<?php
$a = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
usort($a, 'strcmp');
var_export($a);
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
