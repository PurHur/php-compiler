--TEST--
stdlib octdec() — backed enum case TypeError (#5875, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum Eo: string {
    case B = '77';
}

try {
    octdec(Eo::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
octdec(): Argument #1 ($octal_string) must be of type string, Eo given
