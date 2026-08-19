<?php
/**
 * #32492 — logical xor uses zend_is_true, not i64 icmp of object/array pointers.
 * php-src: Zend/zend_operators.c zend_is_true / ZEND_BOOL_XOR
 */
var_dump(new stdClass() xor 0);
var_dump(new stdClass() xor 1);
$o = new stdClass();
var_dump($o xor false);
class C32492 {}
var_dump(new C32492() xor 0);
var_dump([] xor 1);
var_dump([1] xor 1);
var_dump([1] xor 0);
