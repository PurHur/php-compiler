--TEST--
stdlib array_map() — enum case string callback TypeError (#7135, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    array_map('strlen', [E::A]);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strlen(): Argument #1 ($string) must be of type string, E given
