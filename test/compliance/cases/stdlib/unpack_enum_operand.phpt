--TEST--
stdlib unpack() — backed enum case TypeError (#6078, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

try {
    unpack('C', E::A);
    echo "uncaught data\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    unpack(E::A, "\x01");
    echo "uncaught format\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$r = unpack('C', "\x01");
echo 'ok ', $r[1], "\n";
--EXPECT--
unpack(): Argument #2 ($string) must be of type string, E given
unpack(): Argument #1 ($format) must be of type string, E given
ok 1
