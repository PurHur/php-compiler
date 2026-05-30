--TEST--
Language: loose == numeric-string juggling (#3644, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == 'a');
var_dump(in_array(0, ['a'], false));
var_dump(array_search(0, ['a'], false));
var_dump(in_array(0, ['a'], true));
var_dump(0 == '0');
var_dump(1 == '1');
var_dump(0 == '');
?>
--EXPECT--
bool(true)
bool(true)
int(0)
bool(false)
bool(true)
bool(true)
bool(true)
