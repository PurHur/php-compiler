--TEST--
stdlib array_udiff/uintersect/diff_u* user-comparator family (#5644, ext/standard/array.c)
--FILE--
<?php
$cmp = static fn ($a, $b) => $a <=> $b;
$keycmp = static fn ($a, $b) => strcmp((string) $a, (string) $b);

var_export(array_udiff([1, 2], [2, 3], $cmp));
echo "\n";
var_export(array_uintersect([1, 2, 3], [2, 3, 4], $cmp));
echo "\n";
var_export(array_udiff_assoc(['a' => 1, 'b' => 2], ['a' => 1], $keycmp));
echo "\n";
var_export(array_uintersect_assoc(['a' => 1, 'b' => 2], ['a' => 1], $keycmp));
echo "\n";
var_export(array_udiff_uassoc(['a' => 1], ['a' => 1], $cmp, $keycmp));
echo "\n";
var_export(array_uintersect_uassoc(['a' => 1, 'b' => 2], ['a' => 1], $cmp, $keycmp));
echo "\n";
var_export(array_diff_uassoc(['a' => 1, 'b' => 2], ['a' => 2], $cmp));
echo "\n";
var_export(array_intersect_uassoc(['a' => 1, 'b' => 2], ['a' => 1], $cmp));
echo "\n";
var_export(array_diff_ukey(['a' => 1, 'b' => 2], ['A' => 3], 'strcasecmp'));
echo "\n";
foreach ([
    'array_udiff',
    'array_udiff_assoc',
    'array_udiff_uassoc',
    'array_uintersect',
    'array_uintersect_assoc',
    'array_uintersect_uassoc',
    'array_diff_uassoc',
    'array_intersect_uassoc',
    'array_diff_ukey',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
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
array (
  'a' => 1,
)
array (
)
array (
  'a' => 1,
)
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 1,
)
array (
  'b' => 2,
)
array_udiff=true
array_udiff_assoc=true
array_udiff_uassoc=true
array_uintersect=true
array_uintersect_assoc=true
array_uintersect_uassoc=true
array_diff_uassoc=true
array_intersect_uassoc=true
array_diff_ukey=true
