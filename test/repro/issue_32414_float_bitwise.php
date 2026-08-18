<?php
/**
 * #32414 — native float bitwise via convert_to_long / zend_dval_to_lval.
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function
 */
function bits(float $a, int $b, float $c, string $s): void
{
    var_dump($a & $b);
    var_dump($b & $a);
    var_dump($a | $b);
    var_dump($a ^ $b);
    var_dump($a & $c);
    var_dump($a & $s);
    var_dump($s & $a);
}
bits(5.0, 3, 3.0, '7');
