--TEST--
stdlib bcround() — PHP 8.4 arbitrary-precision rounding (ext/bcmath/bcmath.c, #5935)
--FILE--
<?php
var_export(function_exists('bcround'));
echo "\n";
echo bcround('2.5', 0), "\n";
echo bcround('2.5', 0, RoundingMode::HalfTowardsZero), "\n";
echo bcround('2.5', 0, RoundingMode::HalfEven), "\n";
echo bcround('-2.5', 0), "\n";
echo bcround('21.123', 1), "\n";
echo bcround('21.123', 4), "\n";
echo bcround('0.1', -3), "\n";
echo bcround('50', -2), "\n";
echo bcround('50', -2, RoundingMode::HalfTowardsZero), "\n";
try {
    bcround('not-a-number');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
enum E: string { case X = '1.5'; }
try {
    bcround(E::X, 0);
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    bcround('1', 0, 99);
    echo "int-mode-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    bcround('1.25', 1, PHP_ROUND_HALF_UP);
    echo "legacy-int-uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
3
2
2
-3
21.1
21.1230
0
100
0
bcround(): Argument #1 ($num) is not well-formed
bcround(): Argument #1 ($num) must be of type string, E given
bcround(): Argument #3 ($mode) must be of type RoundingMode, int given
bcround(): Argument #3 ($mode) must be of type RoundingMode, int given
