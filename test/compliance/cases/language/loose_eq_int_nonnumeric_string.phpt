--TEST--
Language: loose == int 0 vs non-numeric string (PHP 8.2+, #5178, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == 'foo');
var_dump(in_array(0, ['foo'], false));
var_dump(array_search(0, ['foo'], false));
var_dump(0 == '0');
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(true)
