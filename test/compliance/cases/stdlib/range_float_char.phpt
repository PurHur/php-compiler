--TEST--
stdlib range() float/char/numeric-string bounds (#4258, ext/standard/array.c)
--FILE--
<?php
var_export(range(1.5, 3.5, 0.5));
echo "\n";
var_export(range('a', 'd'));
echo "\n";
var_export(range('1', '3', '1'));
echo "\n";
var_export(range('z', 'a'));
echo "\n";
--EXPECT--
array (
  0 => 1.5,
  1 => 2.0,
  2 => 2.5,
  3 => 3.0,
  4 => 3.5,
)
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
  3 => 'd',
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 'z',
  1 => 'y',
  2 => 'x',
  3 => 'w',
  4 => 'v',
  5 => 'u',
  6 => 't',
  7 => 's',
  8 => 'r',
  9 => 'q',
  10 => 'p',
  11 => 'o',
  12 => 'n',
  13 => 'm',
  14 => 'l',
  15 => 'k',
  16 => 'j',
  17 => 'i',
  18 => 'h',
  19 => 'g',
  20 => 'f',
  21 => 'e',
  22 => 'd',
  23 => 'c',
  24 => 'b',
  25 => 'a',
)
