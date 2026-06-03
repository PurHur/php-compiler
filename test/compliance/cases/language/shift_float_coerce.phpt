--TEST--
Language: << and >> with float operands truncate to int (zend_operators.c, #5270)
--FILE--
<?php
var_dump(1 << 1.5);
var_dump(1.5 << 1);
var_dump(8 >> 1.5);
?>
--EXPECT--
int(2)
int(2)
int(4)
