<?php
/**
 * Maintainer parity probe for #20586.
 */
var_export(function_exists('gmp_kronecker'));
echo ' ';
var_export(function_exists('gmp_divexact'));
echo "\n";
echo gmp_kronecker(2, 5), ' ', gmp_strval(gmp_divexact(10, 5)), "\n";
