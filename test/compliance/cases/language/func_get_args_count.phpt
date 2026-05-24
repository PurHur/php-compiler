--TEST--
func_get_args and func_num_args in user functions (VM, issue #197)
--FILE--
<?php
function show_args(...$ignored) {
    echo func_num_args(), "\n";
    $args = func_get_args();
    echo count($args), "\n";
    echo $args[0], "\n";
    echo $args[1], "\n";
}
show_args('x', 'y');
--EXPECT--
2
2
x
y
