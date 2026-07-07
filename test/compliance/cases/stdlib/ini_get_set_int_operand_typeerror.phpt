--TEST--
stdlib ini_get()/ini_set() — int option operand TypeError (#17268, ext/standard/ini.c)
--FILE--
<?php
try {
    ini_get(123);
    echo "ini_get: uncaught\n";
} catch (TypeError $e) {
    echo "ini_get: ", $e->getMessage(), "\n";
}

try {
    ini_set(456, 'x');
    echo "ini_set: uncaught\n";
} catch (TypeError $e) {
    echo "ini_set: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
ini_get: ini_get(): Argument #1 ($option) must be of type string, int given
ini_set: ini_set(): Argument #1 ($option) must be of type string, int given
