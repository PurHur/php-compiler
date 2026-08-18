<?php
/**
 * #32422 — AOT integer subtraction overflow must promote to float
 * (zend_operators.h fast_long_sub_function). Leftover of #31964 +/*.
 */
var_dump(PHP_INT_MIN - 1);
var_dump(0 - PHP_INT_MIN);
function subov(int $a, int $b): void
{
    var_dump($a - $b);
}
subov(PHP_INT_MIN, 1);
subov(0, PHP_INT_MIN);
var_dump(5 - 3);
