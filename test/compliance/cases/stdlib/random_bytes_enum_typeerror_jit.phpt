--TEST--
stdlib random_bytes() JIT — backed enum case TypeError (#6160)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    random_bytes(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
random_bytes(): Argument #1 ($length) must be of type int, E given
