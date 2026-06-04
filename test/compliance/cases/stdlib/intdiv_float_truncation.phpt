--TEST--
stdlib intdiv() — float operands truncate toward zero (#5360)
--FILE--
<?php
var_export(intdiv(5.0, 2));
echo "\n";
var_export(intdiv(-5.9, 2));
echo "\n";
var_export(intdiv(0.9, 3));
echo "\n";
try {
    intdiv(5.5, 0.0);
} catch (DivisionByZeroError $e) {
    echo 'DivisionByZeroError', "\n";
    echo $e->getMessage(), "\n";
}
try {
    intdiv(NAN, 1);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
try {
    intdiv(1, INF);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
2
-2
0
DivisionByZeroError
Division by zero
TypeError
intdiv(): Argument #1 ($num1) must be of type int, float given
TypeError
intdiv(): Argument #2 ($num2) must be of type int, float given
