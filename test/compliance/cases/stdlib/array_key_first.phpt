--TEST--
stdlib array_key_first()
--FILE--
<?php
$k = array_key_first([]);
echo $k === null ? "empty\n" : "bad\n";
$k = array_key_first([10, 20]);
echo $k, "\n";
$a = ['x' => 1, 'y' => 2];
$k = array_key_first($a);
echo $k, "\n";
try {
    array_key_first(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
empty
0
x
TypeError: array_key_first(): Argument #1 ($array) must be of type array, null given
