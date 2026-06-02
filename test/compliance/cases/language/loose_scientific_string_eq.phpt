--TEST--
Language: loose == int↔scientific string (#4035, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == '0e123');
var_dump('0e123' == 0);
var_dump(0 == '0e5');
var_dump('0e5' == 0);
var_dump(0 == '0');
var_dump(1 == '1abc');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
