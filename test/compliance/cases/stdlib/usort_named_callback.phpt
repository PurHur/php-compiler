--TEST--
stdlib usort()/uasort()/uksort() array:/callback: named parameters (#10048, ext/standard/array.c)
--FILE--
<?php
$a = [3, 1, 2];
usort(array: $a, callback: fn ($x, $y) => $x <=> $y);
var_export($a);
echo "\n";

$b = ['b' => 2, 'a' => 1];
uasort(array: $b, callback: fn ($x, $y) => $x <=> $y);
var_export($b);
echo "\n";

$c = ['b' => 2, 'a' => 1];
uksort(array: $c, callback: fn ($x, $y) => $x <=> $y);
var_export($c);
?>
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
