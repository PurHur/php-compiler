--TEST--
stdlib getrusage() JIT — backed enum case TypeError (#6707)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    getrusage(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, E given
