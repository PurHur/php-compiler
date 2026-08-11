--TEST--
AOT fpow(null) TypeError under strict_types (#30021, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(fpow(null, 2.0));
    echo "\nbad\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fpow(): Argument #1 ($num) must be of type float, null given
