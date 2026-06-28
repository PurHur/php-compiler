--TEST--
stdlib array_diff_uassoc()/array_intersect_uassoc() value callback (#13098)
--FILE--
<?php
$cmp = fn($a, $b) => $a <=> $b;
var_export(array_diff_uassoc([0 => 1], [0 => true], $cmp));
echo "\n";
var_export(array_intersect_uassoc([0 => 1], [0 => true], $cmp));
echo "\n";
--EXPECT--
array (
)
array (
  0 => 1,
)
