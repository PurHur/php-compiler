--TEST--
stdlib array_reduce() int reducer + backed enum case TypeError (#8972, ext/standard/array.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    array_reduce([E::A, E::B], fn($c, $i) => $c + $i, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unsupported operand types: int + E
