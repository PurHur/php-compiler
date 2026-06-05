--TEST--
stdlib fdiv() — enum case operand TypeError (#6185, ext/standard/math.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    fdiv(E::A, 1.0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    fdiv(1.0, E::A);
    echo "uncaught2\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fdiv(): Argument #1 ($num1) must be of type float, E given
fdiv(): Argument #2 ($num2) must be of type float, E given
