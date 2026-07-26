--TEST--
AOT: array_fill_keys() named keys:/value: arguments (#23490)
--FILE--
<?php
var_export(array_fill_keys(keys: ['a', 'b'], value: 1));
echo "\n";
var_export(array_fill_keys(['x'], value: 2));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 1,
)
array (
  'x' => 2,
)
