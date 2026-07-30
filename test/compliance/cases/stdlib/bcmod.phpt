--TEST--
stdlib bcmod() — remainder with truncate-toward-zero quotient (ext/bcmath/bcmath.c, #6042 / #25404)
--FILE--
<?php
if (!function_exists('bcmod')) {
    echo "missing\n";
    exit(1);
}
echo bcmod('10', '3'), "\n";
echo bcmod('5', '3'), "\n";
echo bcmod('5', '2'), "\n";
echo bcmod('7', '4'), "\n";
echo bcmod('-5', '3'), "\n";
echo bcmod('10.5', '3', 1), "\n";
echo bcdiv('5', '3', 0), "\n";
echo "ok\n";
--EXPECT--
1
2
1
3
-2
1.5
1
ok
