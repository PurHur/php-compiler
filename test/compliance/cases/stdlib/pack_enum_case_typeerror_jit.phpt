--TEST--
stdlib pack() JIT — backed enum case numeric operand TypeError (#8816)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    pack('c', E::A);
    echo "c uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    pack('i', E::A);
    echo "i uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
pack(): Argument #2 ($values) must be of type int, E given
pack(): Argument #2 ($values) must be of type int, E given
