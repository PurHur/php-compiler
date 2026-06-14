--TEST--
stdlib bcmod() — remainder (ext/bcmath/bcmath.c, #6042)
--FILE--
<?php
if (!function_exists('bcmod')) {
    echo "missing\n";
    exit(1);
}
echo bcmod('10', '3'), "\n";
echo bcmod('10.5', '3', 1), "\n";
echo "ok\n";
--EXPECT--
1
1.5
ok
