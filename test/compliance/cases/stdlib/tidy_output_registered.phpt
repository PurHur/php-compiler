--TEST--
tidy_clean_repair / tidy_get_output / tidy::$value registered (#21499)
--FILE--
<?php
echo (int) function_exists('tidy_clean_repair'), "\n";
echo (int) function_exists('tidy_get_output'), "\n";
echo (int) property_exists('tidy', 'value'), "\n";
?>
--EXPECT--
1
1
1
