<?php
/**
 * #35305 — unary ~ on TYPE_VALUE string must be byte-wise, not int coerce.
 * Leftover of #35301 (literal/typed string path).
 *
 * php-src: Zend/zend_operators.c — bitwise_not_function string path
 */
$s = 'a';
echo bin2hex(~$s), PHP_EOL;
$t = 'ab';
echo bin2hex(~$t), PHP_EOL;
var_dump(~$s);
$n = 5;
var_dump(~$n);
