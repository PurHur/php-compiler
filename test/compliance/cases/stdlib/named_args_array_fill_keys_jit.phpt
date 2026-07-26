--TEST--
array_fill_keys named keys:/value: arguments (JIT, issue #23490)
--FILE--
<?php
var_export(array_fill_keys(keys: ['a', 'b'], value: 1));
echo PHP_EOL;
var_export(array_fill_keys(['x'], value: 2));
echo PHP_EOL;
--EXPECT--
array (
  'a' => 1,
  'b' => 1,
)
array (
  'x' => 2,
)
