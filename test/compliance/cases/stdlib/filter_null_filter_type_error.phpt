--TEST--
stdlib filter_var()/filter_input() null filter TypeError (ext/filter/filter.c, #12641)
--FILE--
<?php
try {
    filter_var('x', null);
} catch (Throwable $e) {
    echo 'filter_var: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    filter_input(INPUT_GET, 'q', null);
} catch (Throwable $e) {
    echo 'filter_input: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
filter_var: TypeError: filter_var(): Argument #2 ($filter) must be of type int, null given
filter_input: TypeError: filter_input(): Argument #3 ($filter) must be of type int, null given
