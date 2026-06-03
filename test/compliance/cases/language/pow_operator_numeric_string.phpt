--TEST--
Power operator (**) with numeric-string operands (zend_operators.c)
--FILE--
<?php
echo 2 ** "3", "\n";
echo "2" ** 3, "\n";
--EXPECT--
8
8
