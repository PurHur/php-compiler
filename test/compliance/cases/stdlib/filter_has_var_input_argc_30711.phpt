--TEST--
stdlib filter_has_var/filter_input excess argc ArgumentCountError (#30711, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);
try {
    filter_has_var(INPUT_GET, 'x', 1);
    echo "has_var_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_input(INPUT_GET, 'x', FILTER_DEFAULT, 0, 1);
    echo "input_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_has_var(INPUT_GET);
    echo "has_var_short_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_input(INPUT_GET);
    echo "input_short_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: filter_has_var() expects exactly 2 arguments, 3 given
ArgumentCountError: filter_input() expects at most 4 arguments, 5 given
ArgumentCountError: filter_has_var() expects exactly 2 arguments, 1 given
ArgumentCountError: filter_input() expects at least 2 arguments, 1 given
