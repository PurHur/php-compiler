--TEST--
Language: << and >> with bool operands promote to int (zend_operators.c, #5021)
--FILE--
<?php
var_dump(true << 2);
var_dump(false >> 1);
var_dump(true >> 0);
--EXPECT--
int(4)
int(0)
int(1)
