--TEST--
gmp_kronecker / gmp_divexact (#20586)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'kronecker=', function_exists('gmp_kronecker') ? '1' : '0', "\n";
echo 'divexact=', function_exists('gmp_divexact') ? '1' : '0', "\n";
echo 'k25=', gmp_kronecker(2, 5), "\n";
echo 'k26=', gmp_kronecker(2, 6), "\n";
echo 'jacobi=', gmp_jacobi(2, 5), "\n";
echo 'exact=', gmp_strval(gmp_divexact(10, 5)), "\n";
echo 'exact_gmp=', gmp_strval(gmp_divexact(gmp_init(21), gmp_init(7))), "\n";
try {
    gmp_divexact(10, 0);
    echo "div0=ok\n";
} catch (DivisionByZeroError $e) {
    echo "div0=DivisionByZeroError\n";
}
?>
--EXPECT--
kronecker=1
divexact=1
k25=-1
k26=0
jacobi=-1
exact=2
exact_gmp=3
div0=DivisionByZeroError
