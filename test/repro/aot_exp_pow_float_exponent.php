<?php
/**
 * #35058 — AOT exp()/pow()/** must match Zend for float exponents.
 * NestedJIT compound && scale loop left 2^|n| at 1; boxed floats truncated to int.
 */
echo 'exp1=', exp(1.0), "\n";
echo 'exp2=', exp(2.0), "\n";
echo 'sqrt9=', pow(9.0, 0.5), "\n";
echo 'sqrt4=', pow(4.0, 0.5), "\n";
echo 'p23_5=', pow(2.0, 3.5), "\n";
echo 'star=', 2 ** 3.5, "\n";
$e = 3.5;
echo 'var_pow=', pow(2, $e), "\n";
echo 'var_star=', 2 ** $e, "\n";
$a = 2;
$b = 3;
echo 'int_pow=';
var_dump(pow($a, $b));
echo 'lit_int=';
var_dump(pow(2, 3));
