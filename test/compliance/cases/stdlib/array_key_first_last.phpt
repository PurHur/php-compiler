--TEST--
stdlib array_key_first() / array_key_last() — key walk parity (#9071, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

var_export(array_key_first([]));
echo "\n";
var_export(array_key_last([]));
echo "\n";

var_export(array_key_first(['a' => 1, 'b' => 2]));
echo "\n";
var_export(array_key_last(['a' => 1, 'b' => 2]));
echo "\n";

var_export(array_key_first([10 => 'x', 20 => 'y']));
echo "\n";
var_export(array_key_last([10 => 'x', 20 => 'y']));
echo "\n";

var_export(array_key_first(['0' => 'zero', 1 => 'one']));
echo "\n";
var_export(array_key_last(['0' => 'zero', 1 => 'one']));
echo "\n";

try {
    array_key_first('not array');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
NULL
NULL
'a'
'b'
10
20
0
1
TypeError: array_key_first(): Argument #1 ($array) must be of type array, string given
