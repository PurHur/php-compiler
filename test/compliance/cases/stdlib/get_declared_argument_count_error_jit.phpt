--TEST--
Stdlib: get_declared_* — ArgumentCountError for any argument (JIT, #27900)
--JIT--
--FILE--
<?php
foreach (['get_declared_classes', 'get_declared_traits', 'get_declared_interfaces'] as $fn) {
    try {
        $fn(true);
        echo $fn, ": one_arg_ok\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        $fn(true, false);
        echo $fn, ": two_arg_no_error\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
get_declared_classes: ArgumentCountError: get_declared_classes() expects exactly 0 arguments, 1 given
get_declared_classes: ArgumentCountError: get_declared_classes() expects exactly 0 arguments, 2 given
get_declared_traits: ArgumentCountError: get_declared_traits() expects exactly 0 arguments, 1 given
get_declared_traits: ArgumentCountError: get_declared_traits() expects exactly 0 arguments, 2 given
get_declared_interfaces: ArgumentCountError: get_declared_interfaces() expects exactly 0 arguments, 1 given
get_declared_interfaces: ArgumentCountError: get_declared_interfaces() expects exactly 0 arguments, 2 given
