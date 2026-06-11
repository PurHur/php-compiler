--TEST--
JIT: array_flip() — warn and skip invalid values (#4295)
--FILE--
<?php
var_export(array_flip(['x' => true]));
echo "\n";
var_export(array_flip(['a' => 1, 'b' => true, 'c' => 2]));
echo "\n";
--EXPECT--
PHP Warning:  array_flip(): Can only flip string and integer values, entry skipped
PHP Warning:  array_flip(): Can only flip string and integer values, entry skipped
array (
)
array (
  1 => 'a',
  2 => 'c',
)
