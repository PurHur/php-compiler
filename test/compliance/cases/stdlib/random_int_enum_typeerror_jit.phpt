--TEST--
stdlib random_int() JIT — backed enum case TypeError (#5795)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    random_int(E::A, 5);
    echo "min uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    random_int(1, E::A);
    echo "max uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
random_int(): Argument #1 ($min) must be of type int, E given
random_int(): Argument #2 ($max) must be of type int, E given
