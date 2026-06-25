--TEST--
func_get_arg in user functions (JIT, issue #11614)
--JIT--
--FILE--
<?php
function show_arg($a, $b) {
    echo func_get_arg(0), "\n";
    echo func_get_arg(1), "\n";
}
show_arg('x', 'y');
--EXPECT--
x
y
