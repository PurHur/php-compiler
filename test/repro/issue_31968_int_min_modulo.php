<?php
/**
 * #32289 / remaining #31968 — PHP_INT_MIN % -1 must be 0.
 * php-src Zend/zend_operators.c mod_function() special-cases ZEND_LONG_MIN % -1.
 */
var_dump(PHP_INT_MIN % -1);
$a = PHP_INT_MIN;
$b = -1;
var_dump($a % $b);
var_dump($a % -1.0);
var_dump(7 % 3);
