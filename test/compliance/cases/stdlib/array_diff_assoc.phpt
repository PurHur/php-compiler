--TEST--
stdlib array_diff_assoc() strict key+value compare (#3129)
--FILE--
<?php
$a = ['k' => 1, 'x' => 2];
$b = ['k' => 1, 'y' => 3];
var_export(array_diff_assoc($a, $b));
echo "\n";
$c = ['k' => 1, 'x' => 99];
var_export(array_diff_assoc($a, $c));
echo "\n";
--EXPECT--
array (
  'x' => 2,
)
array (
  'x' => 2,
)
