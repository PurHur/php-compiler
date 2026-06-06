--TEST--
stdlib enum_exists() — backed enum case TypeError (#6561, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'a';
}

try {
    enum_exists(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
enum_exists(): Argument #1 ($enum) must be of type string, E given
