--TEST--
AOT: in_array/array_search(null $strict) under strict_types TypeError (#29866, ext/standard/array.c Z_PARAM_BOOL)
--FILE--
<?php
declare(strict_types=1);

try {
    var_export(in_array(1, [1], null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(array_search(1, [1], null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
in_array(): Argument #3 ($strict) must be of type bool, null given
array_search(): Argument #3 ($strict) must be of type bool, null given
