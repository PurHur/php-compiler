--TEST--
stdlib sprintf() %f rounding matches Zend dtoa (ext/standard/snprintf.c, #10796)
--FILE--
<?php
echo sprintf('%.2f', 1.005), "\n";
echo sprintf('%.2f', 2.675), "\n";
echo sprintf('%.0f', 0.5), "\n";
echo sprintf('%.0f', 1.5), "\n";
?>
--EXPECT--
1.00
2.67
0
2
