--TEST--
stdlib bcpowmod() — modular exponentiation (ext/bcmath/bcmath.c, #6976)
--FILE--
<?php
if (!function_exists('bcpowmod')) {
    echo "missing\n";
    exit(1);
}
echo bcpowmod('2', '10', '1000'), "\n";
echo bcpowmod('3', '4', '7'), "\n";
echo bcpowmod('2', '0', '5'), "\n";
echo "ok\n";
--EXPECT--
24
4
1
ok
