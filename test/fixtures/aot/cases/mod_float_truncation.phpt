--TEST--
AOT: % truncates float operands to int like Zend (zend_operators.c mod_function, #5082)
--FILE--
<?php
echo 5.7 % 2.2, "\n";
echo 5.9 % 2, "\n";
echo -5.7 % 2.2, "\n";
echo '7' % '3', "\n";
--EXPECT--
1
1
-1
1
