--TEST--
stdlib base_convert() JIT — backed enum case TypeError (#8976)
--FILE--
<?php
declare(strict_types=1);

enum Es: string {
    case A = 'ff';
}

try {
    base_convert(Es::A, 16, 10);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
base_convert(): Argument #1 ($num) must be of type string, Es given
