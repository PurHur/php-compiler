--TEST--
Language: loose == array vs scalar — bool result, not TypeError (#3736, Zend zend_operators.c)
--FILE--
<?php
var_dump(0 == []);
var_dump([] == false);
var_dump([] == true);
var_dump([1] == false);
var_dump(1 == []);
var_dump('x' == []);
var_dump(null == []);
var_dump([] == null);
var_dump([] == 0);
var_dump([] == '');
var_dump([1] == [1]);
var_dump([1] == [2]);
?>
--EXPECT--
bool(false)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(true)
bool(false)
