--TEST--
stdlib ksort() mixed integer and string keys (#4461, ext/standard/array.c)
--FILE--
<?php
$a = [
    2 => 'two',
    '10' => 'ten_str_key',
    1 => 'one',
    'a' => 'A',
];
ksort($a);
var_export(array_keys($a));
echo "\n";
$b = ['b' => 1, 5 => 2, 'a' => 3, 1 => 4];
ksort($b);
var_export(array_keys($b));
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 10,
  3 => 'a',
)
array (
  0 => 1,
  1 => 5,
  2 => 'a',
  3 => 'b',
)
