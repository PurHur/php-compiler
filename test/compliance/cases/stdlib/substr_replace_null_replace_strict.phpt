--TEST--
stdlib substr_replace(null $replace) under strict_types TypeError (#29874, ext/standard/string.c Z_PARAM_STR_OR_ARR)
--FILE--
<?php
declare(strict_types=1);

try {
    var_dump(substr_replace('abc', null, 1));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(substr_replace(['abc', 'def'], null, 1));
    echo "uncaught-array\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_replace(): Argument #2 ($replace) must be of type array|string, null given
substr_replace(): Argument #2 ($replace) must be of type array|string, null given
