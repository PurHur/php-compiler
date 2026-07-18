<?php
// issue #20519 — gmp_binomial missing (php-src ext/gmp/gmp.c)
echo 'exists=', function_exists('gmp_binomial') ? 'yes' : 'no', "\n";
if (!function_exists('gmp_binomial')) {
    exit(0);
}
echo gmp_strval(gmp_binomial(5, 2)), "\n";
echo gmp_strval(gmp_binomial(10, 0)), "\n";
echo gmp_strval(gmp_binomial(10, 10)), "\n";
echo gmp_strval(gmp_binomial(20, 5)), "\n";
echo gmp_strval(gmp_binomial('100', 3)), "\n";
echo gmp_strval(gmp_binomial(5, 6)), "\n";
echo gmp_strval(gmp_binomial(-5, 3)), "\n";
echo gmp_strval(gmp_binomial(gmp_init(8), 3)), "\n";
try {
    gmp_binomial(5, -1);
    echo "neg_k=ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
