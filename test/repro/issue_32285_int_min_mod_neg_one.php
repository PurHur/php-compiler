<?php
/**
 * #32285 — PHP_INT_MIN % -1 must be 0 (zend_operators.c mod_function).
 */
var_dump(PHP_INT_MIN % -1);
$a = PHP_INT_MIN;
$b = -1;
var_dump($a % $b);
echo (7 % -1), "\n";
