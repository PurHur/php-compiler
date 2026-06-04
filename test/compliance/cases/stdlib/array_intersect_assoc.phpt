--TEST--
stdlib array_intersect_assoc() strict key+value compare (#3129)
--FILE--
<?php
$a = ['k' => 1, 'x' => 2];
$b = ['k' => 1, 'y' => 3, 'x' => 2];
var_export(array_intersect_assoc($a, $b));
echo "\n";
$d = array_intersect_assoc($a, $b, ['k' => 1]);
echo count($d), "\n";
--EXPECT--
array (
  'k' => 1,
  'x' => 2,
)
1
