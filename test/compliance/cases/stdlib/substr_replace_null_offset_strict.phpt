--TEST--
stdlib substr_replace(null $offset) under strict_types TypeError (#31359, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    var_dump(substr_replace('abcd', 'X', null));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_dump(substr_replace(['abcd', 'efgh'], 'X', null));
    echo "uncaught-array\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
substr_replace(): Argument #3 ($offset) must be of type array|int, null given
substr_replace(): Argument #3 ($offset) must be of type array|int, null given
