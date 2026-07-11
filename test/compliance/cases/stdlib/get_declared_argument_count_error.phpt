--TEST--
Stdlib: get_declared_* — ArgumentCountError only for more than one arg (#12177, #4595)
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
get_declared_classes: one_arg_ok
get_declared_classes: ArgumentCountError: get_declared_classes() expects at most 1 argument, 2 given
get_declared_traits: one_arg_ok
get_declared_traits: ArgumentCountError: get_declared_traits() expects at most 1 argument, 2 given
get_declared_interfaces: one_arg_ok
get_declared_interfaces: ArgumentCountError: get_declared_interfaces() expects at most 1 argument, 2 given
