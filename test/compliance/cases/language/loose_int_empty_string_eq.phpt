--TEST--
Language: loose == int↔empty string (#3686, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == '');
var_dump('' == 0);
var_dump(0 == '0');
var_dump(0 == false);
var_dump(0 == 'a');
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
