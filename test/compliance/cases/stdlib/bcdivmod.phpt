--TEST--
stdlib bcdivmod() — quotient + remainder pair (ext/bcmath/bcmath.c, #6966)
--FILE--
<?php
if (!function_exists('bcdivmod')) {
    echo "missing\n";
    exit(1);
}
[$q, $r] = bcdivmod('10.5', '3.2', 2);
echo $q, "\n";
echo $r, "\n";
[$q2, $r2] = bcdivmod('10', '3', 0);
echo $q2, "\n";
echo $r2, "\n";
try {
    bcdivmod('1', '0');
    echo "no_dze\n";
} catch (DivisionByZeroError $e) {
    echo "dze\n";
}
echo "ok\n";
--EXPECT--
3
0.90
3
1
dze
ok
