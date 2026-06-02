--TEST--
stdlib array_key_last()
--FILE--
<?php
$k = array_key_last([]);
echo $k === null ? "empty\n" : "bad\n";
$k = array_key_last([10, 20]);
echo $k, "\n";
$a = ['x' => 1, 'y' => 2];
$k = array_key_last($a);
echo $k, "\n";
try {
    array_key_last(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
empty
1
y
TypeError: array_key_last(): Argument #1 ($array) must be of type array, null given
