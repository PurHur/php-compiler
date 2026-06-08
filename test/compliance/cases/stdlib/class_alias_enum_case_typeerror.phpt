--TEST--
stdlib class_alias() — backed enum case TypeError (#6583, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'a';
}

try {
    class_alias('stdClass', E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    class_alias(E::A, 'Alias6583');
    echo "uncaught2\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_alias(): Argument #2 ($alias) must be of type string, E given
class_alias(): Argument #1 ($original class) must be of type string, E given
