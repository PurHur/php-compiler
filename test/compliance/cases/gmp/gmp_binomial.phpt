--TEST--
gmp_binomial C(n,k) (ext/gmp/gmp.c; issue #20519)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('gmp_binomial') ? 'yes' : 'no', "\n";
echo gmp_strval(gmp_binomial(5, 2)), "\n";
echo gmp_strval(gmp_binomial(10, 0)), "\n";
echo gmp_strval(gmp_binomial(10, 10)), "\n";
echo gmp_strval(gmp_binomial(20, 5)), "\n";
echo gmp_strval(gmp_binomial('100', 3)), "\n";
echo gmp_strval(gmp_binomial(5, 6)), "\n";
echo gmp_strval(gmp_binomial(0, 0)), "\n";
echo gmp_strval(gmp_binomial(-1, 2)), "\n";
echo gmp_strval(gmp_binomial(-5, 3)), "\n";
echo gmp_strval(gmp_binomial(gmp_init(8), 3)), "\n";
try {
    gmp_binomial(5, -1);
    echo "neg_k=ok\n";
} catch (ValueError $e) {
    echo "neg_k=ValueError\n";
}
?>
--EXPECT--
yes
10
1
1
15504
161700
0
1
1
-35
56
neg_k=ValueError
