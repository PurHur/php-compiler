<?php
/**
 * #35337 — exact long/long `/` yields int, non-exact yields float (zend_operators.c div_function).
 */
var_dump(10 / 2);
var_dump(7 / 2);
$a = 10;
$b = 2;
var_dump($a / $b);
$c = '10';
var_dump($c / 2);
