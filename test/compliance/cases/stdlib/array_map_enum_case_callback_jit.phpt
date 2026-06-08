--TEST--
stdlib array_map() JIT — enum case string callback TypeError (#7135)
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
