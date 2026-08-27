<?php
/**
 * #35305 — unary ~ on boxed string variable must be byte-wise, not int coerce.
 * Leftover of #35301 (literal/TYPE_STRING path OK; TYPE_VALUE local wrong).
 *
 * php-src: Zend/zend_operators.c — bitwise_not_function string path
 */
$s = 'a';
echo bin2hex(~$s), PHP_EOL;
$t = 'ab';
echo bin2hex(~$t), PHP_EOL;
$u = '5';
echo bin2hex(~$u), PHP_EOL;
var_dump(~$s);
$n = 5;
var_dump(~$n);
