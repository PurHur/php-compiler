<?php
/**
 * #32407 — native long ⊙ numeric-string bitwise.
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function
 * (convert_to_long / is_numeric_string).
 */
function bits(int $n, bool $t, string $s): void
{
    var_dump($n & $s);
    var_dump($s & $n);
    var_dump($t & $s);
    var_dump($s | 2);
    var_dump($n ^ $s);
    // string⊙string &|^ is byte-wise in zend bitwise_*_function — not convert_to_long.
}
bits(5, true, '7');
