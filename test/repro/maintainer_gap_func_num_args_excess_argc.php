<?php

/**
 * #30647 — func_num_args()/func_get_args() excess argc → ArgumentCountError
 * (Zend/zend_builtin_functions.c).
 */
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
