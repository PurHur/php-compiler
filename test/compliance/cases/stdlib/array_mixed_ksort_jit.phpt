--TEST--
stdlib ksort() JIT mixed integer and string keys (#4461)
--JIT--
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
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 10,
  3 => 'a',
)
