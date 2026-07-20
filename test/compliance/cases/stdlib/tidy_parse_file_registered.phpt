--TEST--
tidy_parse_file / tidy::parseString/parseFile registered (#21501)
--FILE--
<?php
echo (int) function_exists('tidy_parse_file'), "\n";
echo (int) method_exists('tidy', 'parseString'), "\n";
echo (int) method_exists('tidy', 'parseFile'), "\n";
?>
--EXPECT--
1
1
1
