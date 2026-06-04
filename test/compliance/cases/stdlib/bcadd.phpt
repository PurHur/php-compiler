--TEST--
stdlib bcadd() — arbitrary precision add (ext/bcmath/bcmath.c, #5969)
--FILE--
<?php
if (!function_exists('bcadd')) {
    echo "missing\n";
    exit(1);
}
echo bcadd('1.234', '5', 2), "\n";
echo bcadd('1.234', '5'), "\n";
echo "ok\n";
--EXPECT--
6.23
6
ok
