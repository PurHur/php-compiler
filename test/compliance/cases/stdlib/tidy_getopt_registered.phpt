--TEST--
tidy_getopt / getConfig / getStatus registered (#21540)
--FILE--
<?php
echo (int) function_exists('tidy_getopt'), "\n";
echo (int) function_exists('tidy_get_config'), "\n";
echo (int) function_exists('tidy_get_status'), "\n";
echo (int) method_exists('tidy', 'getOpt'), "\n";
echo (int) method_exists('tidy', 'getConfig'), "\n";
echo (int) method_exists('tidy', 'getStatus'), "\n";
?>
--EXPECT--
1
1
1
1
1
1
