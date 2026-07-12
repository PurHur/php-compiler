--TEST--
stdlib class_implements/get_class_vars/get_object_vars/call_user_func/call_user_func_array too-few-args ArgumentCountError (#17914, ext/standard/class.c, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    class_implements();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    get_class_vars();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    get_object_vars();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    call_user_func();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

try {
    call_user_func_array();
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
class_implements() expects at least 1 argument, 0 given
get_class_vars() expects exactly 1 argument, 0 given
get_object_vars() expects exactly 1 argument, 0 given
call_user_func() expects at least 1 argument, 0 given
call_user_func_array() expects exactly 2 arguments, 0 given
