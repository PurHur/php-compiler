--TEST--
JIT: array_count_values() — warn and skip invalid entries (#4267)
--FILE--
<?php
var_export(array_count_values([1.5, 2]));
echo "\n";
var_export(array_count_values([new stdClass(), 'b', 'b']));
echo "\n";
--EXPECTF--
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped in %s on line %d
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped in %s on line %d
array (
  2 => 1,
)
array (
  'b' => 2,
)
