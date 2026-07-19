--TEST--
stdlib bcscale() — default bcmath scale (ext/bcmath/bcmath.c, #5969)
--FILE--
<?php
if (!function_exists('bcscale')) {
    echo "missing\n";
    exit(1);
}
echo bcscale(), "\n";
echo bcscale(3), "\n";
echo bcscale(), "\n";
echo bcscale(null), "\n";
echo bcadd('1.234', '5'), "\n";
echo "ok\n";
--EXPECT--
0
0
3
3
6.234
ok
