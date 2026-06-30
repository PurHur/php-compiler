--TEST--
AOT: array_find family string builtin callback ArgumentCountError (#13946)
--FILE--
<?php
try {
    array_all([1, 2, 3], 'is_int');
    echo "no error\n";
} catch (\ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
is_int() expects exactly 1 argument, 2 given
