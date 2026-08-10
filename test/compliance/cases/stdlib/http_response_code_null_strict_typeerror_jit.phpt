--TEST--
stdlib http_response_code(null) under strict_types JIT throws TypeError (#30019, ext/standard/head.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(http_response_code(null));
    echo " literal: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$n = null;
try {
    var_export(http_response_code($n));
    echo " boxed: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

var_export(http_response_code());
echo " omit-ok\n";
--EXPECT--
http_response_code(): Argument #1 ($response_code) must be of type int, null given
http_response_code(): Argument #1 ($response_code) must be of type int, null given
false omit-ok
