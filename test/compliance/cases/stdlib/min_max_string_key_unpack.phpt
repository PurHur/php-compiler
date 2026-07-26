--TEST--
stdlib min()/max() string-key unpack — ArgumentCountError not crash (#23449, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(max(...['a' => 1, 'b' => 2]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(min(...['a' => 3, 'b' => 1]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(max(a: 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(max(1, 2, a: 3));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(max(value: [1, 9], values: 3));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo max(...['0' => 5, '1' => 9]), "\n";
echo max([1, 2, 3]), "\n";
echo max(value: [4, 1, 8]), "\n";
--EXPECT--
ArgumentCountError: max() expects at least 1 argument, 0 given
ArgumentCountError: min() expects at least 1 argument, 0 given
ArgumentCountError: max() expects at least 1 argument, 0 given
ArgumentCountError: max() does not accept unknown named parameters
ArgumentCountError: max() does not accept unknown named parameters
9
3
8
