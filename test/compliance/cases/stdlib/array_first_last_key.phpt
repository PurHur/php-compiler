--TEST--
stdlib array_first_key() / array_last_key() — PHP 8.3 key helpers (#15539)
--FILE--
<?php
$k = array_first_key([]);
echo $k === null ? "empty_first\n" : "bad_first\n";
$k = array_last_key([]);
echo $k === null ? "empty_last\n" : "bad_last\n";
$list = [10, 20, 30];
echo array_first_key($list), "\n";
echo array_last_key($list), "\n";
$a = ['x' => 1, 'y' => 2];
echo array_first_key($a), "\n";
echo array_last_key($a), "\n";
try {
    array_first_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    array_last_key(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
empty_first
empty_last
0
2
x
y
TypeError: array_first_key(): Argument #1 ($array) must be of type array, null given
TypeError: array_last_key(): Argument #1 ($array) must be of type array, null given
