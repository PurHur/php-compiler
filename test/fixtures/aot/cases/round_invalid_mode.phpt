--TEST--
AOT round() accepts out-of-range mode integers like Zend 8.2 (#4509)
--FILE--
<?php
var_export(round(2.5, 0, 99));
echo "\n";
--EXPECT--
3.0
--EXPECT_EXIT--
0
