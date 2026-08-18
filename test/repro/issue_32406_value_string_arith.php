<?php
/**
 * #32406 — boxed null ⊙ numeric-string.
 * php-src: Zend/zend_operators.c convert_scalar_to_number (IS_NULL→0)
 * then is_numeric_string / add_function.
 */
function arith($n, string $s): void
{
    var_dump($n + $s);
    var_dump($s + $n);
    var_dump($n * $s);
    var_dump($n - '1');
}
arith(null, '5');
