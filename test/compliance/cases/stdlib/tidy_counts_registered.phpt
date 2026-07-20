--TEST--
tidy_*_count functions registered (#21541)
--FILE--
<?php
echo (int) function_exists('tidy_error_count'), "\n";
echo (int) function_exists('tidy_warning_count'), "\n";
echo (int) function_exists('tidy_access_count'), "\n";
echo (int) function_exists('tidy_config_count'), "\n";
?>
--EXPECT--
1
1
1
1
