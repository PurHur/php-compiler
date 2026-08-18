<?php
/**
 * #32401 — native bool ⊙ numeric-string.
 * php-src: Zend/zend_operators.c convert_scalar_to_number (IS_TRUE→1, IS_FALSE→0)
 * then is_numeric_string / add_function.
 */
function arith(bool $t, bool $f, string $s): void
{
    var_dump($t + $s);
    var_dump($s + $t);
    var_dump($t * $s);
    var_dump($t - '1');
    var_dump($t / '2');
    var_dump($f * $s);
    var_dump($t <=> '0');
    echo ($t > '0') ? "gt\n" : "ngt\n";
}
arith(true, false, '5');
