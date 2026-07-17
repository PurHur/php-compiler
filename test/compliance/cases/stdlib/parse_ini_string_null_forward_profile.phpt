--TEST--
stdlib PHP 8.4 profile — parse_ini_string(null) TypeError (#18658, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    parse_ini_string(null);
    echo "no_throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
parse_ini_string(): Argument #1 ($ini_string) must be of type string, null given
