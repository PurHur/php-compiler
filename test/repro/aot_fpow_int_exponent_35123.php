<?php
/**
 * #35123 — AOT float**int / pow(float,int) must not yield 1 (NestedJIT powByInt $e peel).
 * php-src: ext/standard/math.c / Zend/zend_operators.c pow_function
 */
echo 'star_fi=';
var_dump(2.5 ** 2);
echo 'pow_fi=';
var_dump(pow(2.5, 2));
echo 'pow_ff=';
var_dump(pow(2.5, 2.0));
$a = 2.5;
$b = 2;
echo 'var_star=';
var_dump($a ** $b);
echo 'neg=';
var_dump(pow(2.5, -2));
echo 'frac=';
var_dump(pow(2.0, 3.5));
