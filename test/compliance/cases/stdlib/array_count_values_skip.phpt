--TEST--
stdlib array_count_values() — warn and skip invalid entries (#4267, ext/standard/array.c)
--FILE--
<?php
var_export(array_count_values([1.5, 2]));
echo "\n";
var_export(array_count_values([true, false]));
echo "\n";
var_export(array_count_values([new stdClass(), 'a', 'a']));
echo "\n";
--EXPECT--
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
array (
  2 => 1,
)
array (
)
array (
  'a' => 2,
)
