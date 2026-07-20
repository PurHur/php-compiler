--TEST--
tidy_repair_string / tidy_repair_file / tidy::repair* registered (#21498)
--FILE--
<?php
echo (int) function_exists('tidy_repair_string'), "\n";
echo (int) function_exists('tidy_repair_file'), "\n";
echo (int) method_exists('tidy', 'repairString'), "\n";
echo (int) method_exists('tidy', 'repairFile'), "\n";
?>
--EXPECT--
1
1
1
1
