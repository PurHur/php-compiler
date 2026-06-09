--TEST--
stdlib ini_get()/ini_set() JIT — enum case option operands TypeError (#5915)
--FILE--
<?php
enum Es: string { case B = 'display_errors'; }

try {
    ini_get(Es::B);
    echo "ini_get uncaught\n";
} catch (TypeError $e) {
    echo 'ini_get: ', $e->getMessage(), "\n";
}

try {
    ini_set(Es::B, '1');
    echo "ini_set uncaught\n";
} catch (TypeError $e) {
    echo 'ini_set: ', $e->getMessage(), "\n";
}
--EXPECT--
ini_get: ini_get(): Argument #1 ($option) must be of type string, Es given
ini_set: ini_set(): Argument #1 ($option) must be of type string, Es given
