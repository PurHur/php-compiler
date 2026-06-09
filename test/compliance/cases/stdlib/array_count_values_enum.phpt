--TEST--
stdlib array_count_values() — enum cases warn and skip (#4267, #5565, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
var_export(array_count_values([E::A, E::A, E::B]));
echo "\n";
--EXPECT--
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
PHP Warning:  array_count_values(): Can only count string and integer values, entry skipped
array (
)
