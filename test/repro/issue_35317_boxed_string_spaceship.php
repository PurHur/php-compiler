<?php
/**
 * #35317 — boxed numeric-string <=> int must be 0 (Zend compare_function).
 * Leftover of #35313 (==) / #34542 (float<=>long).
 *
 * php-src: Zend/zend_operators.c — compare_function
 */
$a = '10';
var_dump($a <=> 10);
$b = 10;
var_dump($a <=> $b);
var_dump($b <=> $a);
var_dump('10' <=> 10);
