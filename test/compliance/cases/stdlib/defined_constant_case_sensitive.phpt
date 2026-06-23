--TEST--
Stdlib: defined()/constant() — internal constant names case-sensitive (#10635, basic_functions.c)
--FILE--
<?php
var_export(defined('php_version'));
echo "\n";
var_export(defined('PHP_VERSION'));
echo "\n";
var_export(defined('e_error'));
echo "\n";
var_export(defined('E_ERROR'));
echo "\n";
var_export(defined('true'));
echo "\n";
var_export(defined('TRUE'));
echo "\n";

try {
    constant('php_version');
    echo "constant-lower-ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

echo constant('PHP_VERSION') === PHP_VERSION ? "version-ok\n" : "version-bad\n";
?>
--EXPECT--
false
true
false
true
true
true
Error
version-ok
