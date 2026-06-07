--TEST--
stdlib var_export() JIT (#5190)
--FILE--
<?php
var_export(['x' => 3, 0 => 'z']);
echo "\n";
echo var_export(7, true), "\n";
--EXPECT--
array (
  'x' => 3,
  0 => 'z',
)
7
