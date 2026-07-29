--TEST--
func_num_args/func_get_args densify skipped leading optionals for named args (#24948)
--FILE--
<?php
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
--EXPECT--
num=2
args=[1,9]
arg1=9
bt=[1,9]
---
cuf_num=2
---
g=3[1,2,9]
g=1[8]
g=2[1,7]
