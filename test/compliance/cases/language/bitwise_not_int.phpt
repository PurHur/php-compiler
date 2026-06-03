--TEST--
Language: unary ~ on int and numeric-string operands (zend_operators.c, #4998)
--FILE--
<?php
echo ~5, "\n";
echo bin2hex(~'5'), "\n";
--EXPECT--
-6
ca
