<?php
/**
 * #32417 — boxed value ⊙ numeric-string bitwise.
 * php-src: Zend/zend_operators.c convert_scalar_to_number (IS_NULL→0)
 * then bitwise_and/or/xor_function (convert_to_long / is_numeric_string).
 */
function bits($n, string $s): void
{
    var_dump($n & $s);
    var_dump($s & $n);
    var_dump($n | $s);
    var_dump($n ^ '1');
}
bits(null, '5');
bits(7, '3');
