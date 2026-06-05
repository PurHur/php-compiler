--TEST--
stdlib unpack() JIT — backed enum case TypeError (#6078, ext/standard/pack.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    unpack('C', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
unpack(): Argument #2 ($string) must be of type string, E given
