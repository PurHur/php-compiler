--TEST--
stdlib array_uasort()/array_uksort() registered and sort like uasort/uksort (issue #5649)
--FILE--
<?php
var_export(function_exists('array_uasort'));
echo "\n";
var_export(function_exists('array_uksort'));
echo "\n";
$a = ['b' => 2, 'a' => 1];
array_uasort($a, fn ($x, $y) => $x <=> $y);
var_export($a);
echo "\n";
$k = ['b' => 1, 'a' => 2];
array_uksort($k, fn ($x, $y) => strcmp((string) $x, (string) $y));
var_export($k);
echo "\n";
--EXPECT--
true
true
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 2,
  'b' => 1,
)
