--TEST--
array_filter/array_reduce/array_walk argc → ArgumentCountError (#28473)
--FILE--
<?php
foreach (['array_filter', 'array_reduce', 'array_walk'] as $fn) {
    try {
        $fn();
        echo "$fn:ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    array_filter([], null, 0, 1);
    echo "array_filter/4:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    array_reduce([]);
    echo "array_reduce/1:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:array_filter() expects at least 1 argument, 0 given
ArgumentCountError:array_reduce() expects at least 2 arguments, 0 given
ArgumentCountError:array_walk() expects at least 2 arguments, 0 given
ArgumentCountError:array_filter() expects at most 3 arguments, 4 given
ArgumentCountError:array_reduce() expects at least 2 arguments, 1 given
