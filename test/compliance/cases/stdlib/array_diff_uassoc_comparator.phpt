--TEST--
stdlib array_diff_uassoc()/array_intersect_uassoc() key comparator (#10280)
--FILE--
<?php
$a = ['a' => 1];
$b = ['A' => 1];
$cmp = static fn ($k1, $k2) => strcasecmp((string) $k1, (string) $k2);
var_export(array_diff_uassoc($a, $b, $cmp));
echo "\n";
var_export(array_intersect_uassoc($a, $b, $cmp));
echo "\n";
?>
--EXPECT--
array (
)
array (
  'a' => 1,
)
