--TEST--
language: func_num_args/func_get_args excess argc → ArgumentCountError JIT (#30647, Zend/zend_builtin_functions.c)
--FILE--
<?php
function f() {
    try {
        func_num_args(1);
        echo "NO_THROW_NUM\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        func_get_args(1);
        echo "NO_THROW_GET\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    echo 'num=', func_num_args(), ' get=', implode(',', func_get_args()), "\n";
}
f('a', 'b');
--EXPECT--
ArgumentCountError: func_num_args() expects exactly 0 arguments, 1 given
ArgumentCountError: func_get_args() expects exactly 0 arguments, 1 given
num=2 get=a,b
