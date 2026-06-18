--TEST--
Stdlib: array_slice()/array_chunk() preserve_keys named parameter (#9105, array.c)
--FILE--
<?php
$a = [0 => 'a', 1 => 'b', 2 => 'c'];
var_export(array_slice($a, 1, 2, preserve_keys: true));
echo "\n";
var_export(array_chunk(['a', 'b', 'c'], 2, preserve_keys: true));
echo "\n";
--EXPECT--
array (
  1 => 'b',
  2 => 'c',
)
array (
  0 => 
  array (
    0 => 'a',
    1 => 'b',
  ),
  1 => 
  array (
    2 => 'c',
  ),
)
