--TEST--
stdlib ini_get()/ini_set() — int option operand TypeError JIT forward 8.4 profile (#17268, #17291, ext/standard/ini.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
