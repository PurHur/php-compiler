--TEST--
tidy_parse_string / tidy class registered (#21464)
--FILE--
<?php
echo (int) function_exists('tidy_parse_string'), "\n";
echo (int) class_exists('tidy'), "\n";
?>
--EXPECT--
1
1
