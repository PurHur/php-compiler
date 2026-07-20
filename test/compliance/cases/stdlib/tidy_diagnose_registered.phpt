--TEST--
tidy_diagnose / tidy_get_error_buffer / errorBuffer registered (#21500)
--FILE--
<?php
echo (int) function_exists('tidy_diagnose'), "\n";
echo (int) function_exists('tidy_get_error_buffer'), "\n";
echo (int) method_exists('tidy', 'diagnose'), "\n";
echo (int) property_exists('tidy', 'errorBuffer'), "\n";
?>
--EXPECT--
1
1
1
1
