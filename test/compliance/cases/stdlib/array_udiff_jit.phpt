--TEST--
stdlib array_udiff/uintersect/diff_ukey — native JIT closure comparator (#9155, ext/standard/array.c)
--FILE--
<?php
$cmp = static fn ($a, $b) => $a <=> $b;
$keycmp = static fn ($a, $b) => strcasecmp((string) $a, (string) $b);

var_export(array_udiff([1, 2, 3], [2, 3, 4], $cmp));
echo "\n";
var_export(array_uintersect([1, 2, 3], [2, 3, 4], $cmp));
echo "\n";
var_export(array_diff_ukey(['a' => 1, 'b' => 2], ['A' => 3], $keycmp));
echo "\n";
--EXPECT--
array (
  0 => 1,
)
array (
  1 => 2,
  2 => 3,
)
array (
  'b' => 2,
)
