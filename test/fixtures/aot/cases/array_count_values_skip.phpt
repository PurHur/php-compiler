--TEST--
AOT: array_count_values() — warn and skip invalid entries (#4267)
--FILE--
<?php
var_export(array_count_values([1.5, 2]));
echo "\n";
var_export(array_count_values([new stdClass(), 'x', 'x']));
echo "\n";
--EXPECT--
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
array (
  2 => 1,
)
array (
  'x' => 2,
)
