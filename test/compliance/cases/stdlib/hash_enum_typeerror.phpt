--TEST--
stdlib hash() — backed enum case TypeError (#5726, ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    hash('md5', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hash(): Argument #2 ($data) must be of type string, E given
