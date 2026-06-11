--TEST--
stdlib array_multisort() single-array form — sort one array in place (#4945, ext/standard/array.c)
--FILE--
<?php
$a = [3, 1, 2];
array_multisort($a);
var_export($a);
echo "\n";
$b = [3, 1, 2];
array_multisort($b, SORT_DESC);
var_export($b);
echo "\n";
$c = ['c', 'a', 'b'];
array_multisort($c);
var_export($c);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 3,
  1 => 2,
  2 => 1,
)
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
