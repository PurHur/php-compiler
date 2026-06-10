--TEST--
stdlib pack() string format rejects enum case operands (#5713, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

enum S: string {
    case X = 'hi';
}

try {
    pack('a3', E::A);
    echo "uncaught int enum\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    pack('a3', S::X);
    echo "uncaught string enum\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class E could not be converted to string
Object of class S could not be converted to string
