--TEST--
stdlib array_map() — null zip across multiple arrays (#4539, ext/standard/array.c)
--FILE--
<?php
var_export(array_map(null, [1, 2], ['a', 'b']));
echo "\n";
var_export(array_map(null, [1, 2], ['a', 'b'], [true, false]));
echo "\n";
var_export(array_map(null, [1, 2, 3], ['a', 'b']));
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 1,
    1 => 'a',
  ),
  1 => array (
    0 => 2,
    1 => 'b',
  ),
)
array (
  0 => array (
    0 => 1,
    1 => 'a',
    2 => true,
  ),
  1 => array (
    0 => 2,
    1 => 'b',
    2 => false,
  ),
)
array (
  0 => array (
    0 => 1,
    1 => 'a',
  ),
  1 => array (
    0 => 2,
    1 => 'b',
  ),
  2 => array (
    0 => 3,
    1 => NULL,
  ),
)
