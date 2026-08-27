<?php
/**
 * #35337 — Zend div_function: exact long÷long stays int; inexact stays float.
 * Leftover of #31968 always-fdiv overcorrection.
 */
var_dump(10 / 2);
var_dump(7 / 2);
$a = 10;
$b = 2;
var_dump($a / $b);
$s = "10";
var_dump($s / 2);
var_dump(PHP_INT_MIN / -1);
