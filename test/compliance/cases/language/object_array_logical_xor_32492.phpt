--TEST--
Language: object/array logical xor is zend_is_true (#32492, Zend/zend_operators.c)
--FILE--
<?php
var_dump(new stdClass() xor 0);
var_dump(new stdClass() xor 1);
$o = new stdClass();
var_dump($o xor false);
class C32492 {}
var_dump(new C32492() xor 0);
var_dump([] xor 1);
var_dump([1] xor 1);
var_dump([1] xor 0);
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
