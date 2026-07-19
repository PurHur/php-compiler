--TEST--
stdlib ini_get()/ini_set(null) — TypeError JIT forward 8.4 profile (#20361, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    ini_get(null);
    echo "ini_get: uncaught\n";
} catch (TypeError $e) {
    echo "ini_get: ", $e->getMessage(), "\n";
}

try {
    ini_set(null, '1');
    echo "ini_set: uncaught\n";
} catch (TypeError $e) {
    echo "ini_set: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
ini_get: ini_get(): Argument #1 ($option) must be of type string, null given
ini_set: ini_set(): Argument #1 ($option) must be of type string, null given
