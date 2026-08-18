<?php
/**
 * #32309 — abs(PHP_INT_MIN + 1) stays int (php-src math.c PHP_FUNCTION(abs)).
 */
var_dump(abs(PHP_INT_MIN + 1));
echo is_float(abs(PHP_INT_MIN)) ? "min-float\n" : "min-not-float\n";
function _abs32309_n(): int
{
    return 5;
}
var_dump(abs(_abs32309_n() + 1));
