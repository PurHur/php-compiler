--TEST--
stdlib array_find family — string builtin callback ArgumentCountError (#13946, ext/standard/array.c)
--FILE--
<?php
foreach (
    [
        static fn () => array_any([1, 2, 3], 'strlen'),
        static fn () => array_all([1, 2, 3], 'is_int'),
        static fn () => array_find([1, 2, 3], 'is_int'),
        static fn () => array_find_key([1, 2, 3], 'is_int'),
    ] as $call
) {
    try {
        $call();
        echo "no error\n";
    } catch (\ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
strlen() expects exactly 1 argument, 2 given
is_int() expects exactly 1 argument, 2 given
is_int() expects exactly 1 argument, 2 given
is_int() expects exactly 1 argument, 2 given
