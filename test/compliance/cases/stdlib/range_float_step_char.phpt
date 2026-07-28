--TEST--
stdlib range() float step forces numeric path for char endpoints (#24399, ext/standard/array.c)
--FILE--
<?php
var_export(range('a', 'e', 1.5));
echo "\n";
var_export(range('A', 'C', 1.5));
echo "\n";
var_export(range('a', 'e', 1.0));
echo "\n";
var_export(range('a', 'e', '1.5'));
echo "\n";
var_export(range('a', 'e', 1));
echo "\n";
var_export(range('1', '5', 1.5));
echo "\n";
--EXPECT--
array (
  0 => 0.0,
)
array (
  0 => 0.0,
)
array (
  0 => 0.0,
)
array (
  0 => 0.0,
)
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
  3 => 'd',
  4 => 'e',
)
array (
  0 => 1.0,
  1 => 2.5,
  2 => 4.0,
)
