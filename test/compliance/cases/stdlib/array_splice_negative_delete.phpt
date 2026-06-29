--TEST--
stdlib array_splice() negative offset delete-only mutates by-ref array (#13425, ext/standard/array.c)
--FILE--
<?php
$a = [0, 1, 2, 3];
$r = array_splice($a, -1, 1);
var_export($a);
echo "\n";
var_export($r);
echo "\n";
$b = [0, 1, 2, 3, 4];
$t = array_splice($b, -2);
echo count($t), "\n";
echo count($b), "\n";
?>
--EXPECT--
array (
  0 => 0,
  1 => 1,
  2 => 2,
)
array (
  0 => 3,
)
2
3
