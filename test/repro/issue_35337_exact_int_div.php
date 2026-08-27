<?php
/**
 * #35337 — php-src div_function keeps IS_LONG when division is exact.
 */
var_dump(10 / 2);
var_dump(7 / 2);
$a = 10;
$b = 2;
var_dump($a / $b);
$a = '10';
var_dump($a / 2);
