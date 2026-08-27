<?php
/**
 * #35313 — boxed numeric-string == boxed int must be true (Zend compare_function).
 * Literals OK; untyped locals both TYPE_VALUE fail via __value__spaceship.
 *
 * php-src: Zend/zend_operators.c — compare_function / is_equal_function
 */
$a = '10';
$b = 10;
var_dump($a == $b);
var_dump($b == $a);
var_dump($a != $b);
var_dump('10' == 10);
