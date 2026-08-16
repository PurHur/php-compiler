--TEST--
Stdlib: set_error_handler(..., null) soft-null DEP for $error_levels (JIT, #31465, Zend/zend_builtin_functions.c)
--FILE--
<?php
error_reporting(E_ALL);

function set_error_handler_null_levels_soft_jit_capture(int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
}

function set_error_handler_null_levels_soft_jit_next(): bool {
    return false;
}

set_error_handler('set_error_handler_null_levels_soft_jit_capture');
try {
    $prev = set_error_handler('set_error_handler_null_levels_soft_jit_next', null);
    echo 'prev_callable=', var_export(is_callable($prev), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ERR[8192]: set_error_handler(): Passing null to parameter #2 ($error_levels) of type int is deprecated
prev_callable=true
