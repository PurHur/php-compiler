--TEST--
stdlib array_udiff_uassoc() — bool-returning closure comparators (#11219, ext/standard/array.c)
--FILE--
<?php
$a = ['x' => 1, 'y' => 2];
$b = ['x' => 1, 'z' => 3];
var_export(array_udiff_uassoc($a, $b, fn ($x, $y) => $x < $y, fn ($k1, $k2) => $k1 < $k2));
echo "\n";
var_export(array_udiff_uassoc(['a' => 1], ['A' => 1], 'strcasecmp', fn ($k1, $k2) => strcasecmp($k1, $k2)));
echo "\n";
--EXPECT--
array (
  'y' => 2,
)
array (
)
