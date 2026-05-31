--TEST--
Language: loose == int↔scientific string (#3658, Zend zend_operators.c)
--CREDITS--
#3658 int↔string loose == accepts integer numeric strings only.
Reference PHP 8.2 may still report 0 == '0e123' as true via float coercion;
this compiler follows the stricter int↔string branch (see issue #3658).
--FILE--
<?php
var_dump(0 == '0e123');
var_dump('0e123' == 0);
var_dump(0 == '0');
var_dump(1 == '1abc');
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(false)
