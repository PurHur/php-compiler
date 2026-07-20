--TEST--
tidy_get_opt_doc / getOptDoc registered (#21604)
--FILE--
<?php
echo (int) function_exists('tidy_get_opt_doc'), "\n";
echo (int) method_exists('tidy', 'getOptDoc'), "\n";
?>
--EXPECT--
1
1
