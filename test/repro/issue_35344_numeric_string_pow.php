<?php
/**
 * #35344 — php-src pow_function keeps IS_LONG for integral numeric-string **.
 */
var_dump(2 ** 3);
$a = 2;
$b = 3;
var_dump($a ** $b);
$a = '2';
var_dump($a ** 3);
$a = '2';
$b = '3';
var_dump($a ** $b);
var_dump('2.5' ** 3);
