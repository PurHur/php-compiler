--TEST--
Stdlib: get_declared_classes/traits/interfaces() — ArgumentCountError for extra args (JIT, #4595)
--JIT--
--FILE--
<?php
foreach (['get_declared_classes', 'get_declared_traits', 'get_declared_interfaces'] as $fn) {
    try {
        $fn(1);
        echo $fn, ": no_error\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
get_declared_classes: ArgumentCountError: get_declared_classes() expects exactly 0 arguments, 1 given
get_declared_traits: ArgumentCountError: get_declared_traits() expects exactly 0 arguments, 1 given
get_declared_interfaces: ArgumentCountError: get_declared_interfaces() expects exactly 0 arguments, 1 given
