--TEST--
stdlib base_convert() — backed enum case TypeError (#8976, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case A = 'ff';
}

enum Ei: int {
    case B = 42;
}

foreach ([Es::A, Ei::B] as $case) {
    try {
        base_convert($case, 10, 16);
        echo "uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
base_convert(): Argument #1 ($num) must be of type string, Es given
base_convert(): Argument #1 ($num) must be of type string, Ei given
