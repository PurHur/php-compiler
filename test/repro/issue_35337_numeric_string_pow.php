<?php
/**
 * #35337 leftover — numeric-string variable ** int keeps int result (zend_operators.c pow_function).
 */
$a = '2';
var_dump($a ** 3);
$a = '2.5';
var_dump($a ** 3);
