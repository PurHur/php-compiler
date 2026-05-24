--TEST--
func_get_args and func_num_args in user functions (JIT, issue #197)
--JIT--
--FILE--
<?php
function show_args() {
    echo func_num_args(), "\n";
    $args = func_get_args();
    echo $args[0], $args[1], "\n";
}
show_args('x', 'y');
--EXPECT--
2
xy
