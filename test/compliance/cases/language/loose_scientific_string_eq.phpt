--TEST--
Language: loose == int↔scientific string (#3658, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == '0e123');
var_dump(0 == '0');
var_dump(1 == '1abc');
?>
--EXPECT--
bool(false)
bool(true)
bool(false)
