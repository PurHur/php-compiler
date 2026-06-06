--TEST--
stdlib ini_set()/ini_get() JIT — enum case operands TypeError (#7017, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    ini_set(E::A, '1');
    echo "ini_set: uncaught\n";
} catch (TypeError $e) {
    echo "ini_set: ", $e->getMessage(), "\n";
}

try {
    ini_get(E::A);
    echo "ini_get: uncaught\n";
} catch (TypeError $e) {
    echo "ini_get: ", $e->getMessage(), "\n";
}

try {
    ini_set('display_errors', E::A);
    echo "ini_set value: uncaught\n";
} catch (TypeError $e) {
    echo "ini_set value: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
ini_set: ini_set(): Argument #1 ($option) must be of type string, E given
ini_get: ini_get(): Argument #1 ($option) must be of type string, E given
ini_set value: ini_set(): Argument #2 ($value) must be of type string|int|float|bool|null
