<?php
/**
 * #32337 — native bool ⊙ int/float/bool.
 * php-src: Zend/zend_operators.c convert_scalar_to_number (IS_TRUE→1, IS_FALSE→0).
 */
function arith(bool $t, bool $f, int $n, float $d): void
{
    var_dump($t + $n);
    var_dump($n + $t);
    var_dump($f * $n);
    var_dump($t - 1);
    var_dump($t / 2);
    var_dump($t + $d);
    var_dump($d + $f);
    var_dump($t + $t);
    var_dump($t <=> 0);
    echo ($t > 0) ? "gt\n" : "ngt\n";
}
arith(true, false, 5, 1.5);
