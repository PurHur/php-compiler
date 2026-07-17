--TEST--
stdlib strtolower()/strtoupper() — backed enum case TypeError (#5943, #8846, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case B = 'AbC';
}

try {
    strtolower(Es::B);
    echo "strtolower uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    strtoupper(Es::B);
    echo "strtoupper uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strtolower(): Argument #1 ($string) must be of type string, Es given
strtoupper(): Argument #1 ($string) must be of type string, Es given
