<?php
/**
 * #32325 remaining #31967 slice — numeric-string ⊙ native float.
 * Zend/zend_operators.c add_function / mul_function / div_function / sub_function.
 */
$s = '5';
$f = 1.5;
var_dump($s + $f);
var_dump($f + $s);
var_dump('5.5' * 2.0);
var_dump($s - $f);
var_dump('10' / 4.0);
var_dump($s <=> $f);
echo ($s > $f) ? "gt\n" : "ngt\n";
