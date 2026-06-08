--TEST--
Stdlib: array_intersect_uassoc() user value compare on exact keys (#4285, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 1, 'c' => 3];
$cmp = static fn ($l, $r) => $l <=> $r;
var_export(array_diff_uassoc($a, $b, $cmp));
echo "\n";
var_export(array_intersect_uassoc($a, $b, $cmp));
echo "\n";
$a2 = ['a' => 1, 'b' => 2];
$b2 = ['a' => 1];
$c2 = ['a' => 1];
var_export(array_intersect_uassoc($a2, $b2, $c2, $cmp));
echo "\n";
echo 'exists=', var_export(function_exists('array_intersect_uassoc'), true), "\n";
--EXPECT--
array (
  'b' => 2,
)
array (
  'a' => 1,
)
array (
  'a' => 1,
)
exists=true
