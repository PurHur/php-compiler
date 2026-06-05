--TEST--
stdlib bindec() — backed enum case TypeError (#5875, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum Eb: string {
    case B = '1010';
}

try {
    bindec(Eb::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
bindec(): Argument #1 ($binary_string) must be of type string, Eb given
