--TEST--
stdlib pack() — backed enum case numeric operand TypeError (#8816, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    case B = 42;
}

try {
    pack('c', E::A);
    echo "c uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    pack('i', E::B);
    echo "i uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$data = pack('i', 1);
echo 'ok ', strlen($data), "\n";
--EXPECT--
pack(): Argument #2 ($values) must be of type int, E given
pack(): Argument #2 ($values) must be of type int, E given
ok 4
