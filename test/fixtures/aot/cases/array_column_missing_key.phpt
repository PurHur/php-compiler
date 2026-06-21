--TEST--
AOT: array_column() missing column key returns empty array (#10443)
--FILE--
<?php
declare(strict_types=1);
var_export(array_column([['a' => 1]], 'b'));
echo "\n";
var_export(array_column([['a' => 1, 'b' => null]], 'b'));
echo "\n";
--EXPECT--
array (
)
array (
  0 => NULL,
)
