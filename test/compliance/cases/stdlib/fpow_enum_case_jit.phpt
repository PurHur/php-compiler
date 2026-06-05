--TEST--
stdlib fpow() JIT — enum case operand TypeError (#5998)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    fpow(E::A, 1.0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    fpow(1.0, E::A);
    echo "uncaught2\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fpow(): Argument #1 ($num) must be of type float, E given
fpow(): Argument #2 ($exponent) must be of type float, E given
