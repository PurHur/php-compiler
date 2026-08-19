--TEST--
AOT: object/array xor match Zend zend_is_true (#32492 leftover of #32471/#32475)
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
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
--EXPECT_EXIT--
0
