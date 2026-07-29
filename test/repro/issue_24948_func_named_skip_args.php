<?php
/**
 * #24948 — func_num_args / func_get_args / func_get_arg / debug_backtrace densify
 * skipped leading optionals when a later named arg is present (Zend/zend_execute.c).
 *
 *   php bin/vm.php test/repro/issue_24948_func_named_skip_args.php
 */
function f($a = 1, $b = 2) {
    echo 'num='.func_num_args()."\n";
    echo 'args='.json_encode(func_get_args())."\n";
    try {
        echo 'arg1='.func_get_arg(1)."\n";
    } catch (Throwable $e) {
        echo get_class($e)."\n";
    }
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
    echo 'bt='.json_encode($bt[0]['args'])."\n";
}
f(b: 9);
echo "---\n";
function h($a = 1, $b = 2) {
    echo 'cuf_num='.func_num_args()."\n";
}
call_user_func('h', b: 9);
echo "---\n";
function g($a = 1, $b = 2, $c = 3) {
    echo 'g='.func_num_args().json_encode(func_get_args())."\n";
}
g(c: 9);
g(a: 8);
g(b: 7);
