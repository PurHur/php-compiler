--TEST--
getrusage(null) under strict_types TypeError (#30361, ext/standard/basic_functions.c Z_PARAM_LONG)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(getrusage(null));
    echo " uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$n = null;
try {
    var_export(getrusage($n));
    echo " uncaught-var\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getrusage(): Argument #1 ($mode) must be of type int, null given
getrusage(): Argument #1 ($mode) must be of type int, null given
