--TEST--
AOT: loose == int↔scientific string (#3658, Zend zend_operators.c)
--FILE--
<?php
echo (0 == '0e123') ? "0\n" : "1\n";
echo ('0e123' == 0) ? "0\n" : "1\n";
echo (0 == '0') ? "1\n" : "0\n";
echo (1 == '1abc') ? "1\n" : "0\n";
?>
--EXPECT--
1
1
1
0
