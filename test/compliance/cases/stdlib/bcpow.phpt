--TEST--
stdlib bcpow() — exponentiation (ext/bcmath/bcmath.c, #6042)
--FILE--
<?php
if (!function_exists('bcpow')) {
    echo "missing\n";
    exit(1);
}
echo bcpow('2', '8'), "\n";
echo bcpow('2', '8', 2), "\n";
echo bcpow('4', '0.5', 1), "\n";
echo "ok\n";
--EXPECT--
256
256.00
2.0
ok