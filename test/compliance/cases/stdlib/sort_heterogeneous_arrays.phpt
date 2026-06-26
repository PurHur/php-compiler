--TEST--
stdlib sort() heterogeneous scalar arrays (#12243, ext/standard/array.c)
--FILE--
<?php
$a = [1, '2'];
sort($a);
var_export($a);
echo "\n";
$b = [1, 2.5];
sort($b);
var_export($b);
echo "\n";
$c = [false, true, true];
sort($c);
var_export($c);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => '2',
)
array (
  0 => 1,
  1 => 2.5,
)
array (
  0 => false,
  1 => true,
  2 => true,
)
