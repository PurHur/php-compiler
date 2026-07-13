--TEST--
AOT: array_uasort() closure comparator on associative int values (#5698)
--FILE--
<?php
$a = ['b' => 2, 'a' => 1];
array_uasort($a, static fn(int $x, int $y): int => $x <=> $y);
var_export($a);
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
