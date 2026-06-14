--TEST--
stdlib bcsqrt() — square root (ext/bcmath/bcmath.c, #6042)
--FILE--
<?php
if (!function_exists('bcsqrt')) {
    echo "missing\n";
    exit(1);
}
echo bcsqrt('2', 3), "\n";
echo bcsqrt('9'), "\n";
echo "ok\n";
--EXPECT--
1.414
3
ok